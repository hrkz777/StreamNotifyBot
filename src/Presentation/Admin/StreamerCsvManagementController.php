<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\ImportStreamerCsv;
use App\Application\Csv\RegisterStreamersFromCsv;
use App\Application\Csv\StreamerCsvCodec;
use App\Application\Csv\StreamerCsvRegistration;
use App\Domain\Catalog\StreamerCatalogRepository;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StreamerCsvManagementController extends AbstractController
{
    private const int MAX_UPLOAD_BYTES = 5_000_000;
    private const string PREVIEW_SESSION_KEY = 'streamer_csv_preview';

    #[Route('/admin/streamers/csv', name: 'admin_streamers_csv', methods: ['GET', 'POST'])]
    public function index(Request $request, StreamerCatalogRepository $streamers, ImportStreamerCsv $importer, RegisterStreamersFromCsv $registrar): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        $preview = null;
        if ($request->isMethod('POST')) {
            if ($request->request->get('action') === 'preview') {
                $preview = $this->preview($request, $importer);
            } elseif ($request->request->get('action') === 'execute') {
                $this->execute($request, $registrar);

                return $this->redirectToRoute('admin_streamers_csv');
            }
        }

        $accountsByStreamer = [];
        foreach ($streamers->findAllPlatformAccounts() as $account) {
            $accountsByStreamer[$account->streamerId][] = $account;
        }

        return $this->page('admin/streamer_csv.html.twig', [
            'streamers' => $streamers->findAllStreamers(),
            'accounts_by_streamer' => $accountsByStreamer,
            'preview' => $preview,
        ]);
    }

    #[Route('/admin/streamers/csv/export', name: 'admin_streamers_csv_export', methods: ['GET'])]
    public function export(StreamerCatalogRepository $streamers, StreamerCsvCodec $csvCodec): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        try {
            $contents = $csvCodec->export($streamers->findAllStreamers(), $streamers->findAllPlatformAccounts());
        } catch (CsvFormatException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_streamers');
        }

        return $this->download($contents, 'streamers.csv');
    }

    /** @return array{registrations: list<StreamerCsvRegistration>, token: string}|null */
    private function preview(Request $request, ImportStreamerCsv $importer): ?array
    {
        if (!$this->isCsrfTokenValid('streamer_csv_preview', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRFトークンが不正です。');
        }

        try {
            $contents = self::uploadedContents($request->files->get('csv_file'));
            $registrations = $importer->preview($contents);
            $token = bin2hex(random_bytes(32));
            $request->getSession()->set(self::PREVIEW_SESSION_KEY, ['contents' => base64_encode($contents), 'token' => $token]);

            return ['registrations' => $registrations, 'token' => $token];
        } catch (CsvFormatException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return null;
        }
    }

    private function execute(Request $request, RegisterStreamersFromCsv $registrar): void
    {
        if (!$this->isCsrfTokenValid('streamer_csv_execute', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRFトークンが不正です。');
        }

        $storedPreview = $request->getSession()->remove(self::PREVIEW_SESSION_KEY);
        if (!is_array($storedPreview)
            || !is_string($storedPreview['contents'] ?? null)
            || !is_string($storedPreview['token'] ?? null)
            || !hash_equals($storedPreview['token'], (string) $request->request->get('preview_token'))
        ) {
            $this->addFlash('error', 'プレビューの有効期限が切れました。CSVをもう一度選択してください。');

            return;
        }
        $contents = base64_decode($storedPreview['contents'], true);
        if ($contents === false) {
            $this->addFlash('error', 'プレビューしたCSVを読み取れませんでした。');

            return;
        }
        try {
            $this->addFlash('success', sprintf('%d件の配信者を登録しました。', $registrar->execute($contents)));
        } catch (CsvFormatException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
    }

    private static function uploadedContents(mixed $file): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InvalidArgumentException('CSVファイルを選択してください。');
        }

        $size = $file->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('CSVファイルは1バイト以上、5MB以下にしてください。');
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new InvalidArgumentException('CSVファイルを読み取れませんでした。');
        }

        return $contents;
    }

    /** @param array<string, mixed> $parameters */
    private function page(string $template, array $parameters): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $response = $this->render($template, $parameters + [
            'content_security_policy_nonce' => $nonce,
            'preview_status' => 'CSVはプレビュー後、確認操作を行った場合だけ登録されます。',
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    private function download(string $contents, string $filename): Response
    {
        $response = new Response($contents);
        $response->headers->set('Content-Type', 'text/csv; charset=Shift_JIS');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Content-Length', (string) strlen($contents));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
