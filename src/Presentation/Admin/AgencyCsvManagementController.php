<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\ImportAgencyCsv;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyRepository;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/agencies/csv', name: 'admin_agencies_csv', methods: ['GET', 'POST'])]
final class AgencyCsvManagementController extends AbstractController
{
    private const int MAX_UPLOAD_BYTES = 5_000_000;
    private const string PREVIEW_SESSION_KEY = 'agency_csv_preview';

    public function __invoke(Request $request, ImportAgencyCsv $importAgencyCsv, AgencyRepository $agencies): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        $preview = null;
        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            if ($action === 'preview') {
                $preview = $this->preview($request, $importAgencyCsv);
            } elseif ($action === 'execute') {
                $this->execute($request, $importAgencyCsv);

                return $this->redirectToRoute('admin_agencies_csv');
            }
        }

        return $this->renderPage($agencies, $preview);
    }

    /** @return array{agencies: list<Agency>, token: string, replace: bool}|null */
    private function preview(Request $request, ImportAgencyCsv $importAgencyCsv): ?array
    {
        if (!$this->isCsrfTokenValid('agency_csv_preview', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRFトークンが不正です。');
        }

        try {
            $contents = self::uploadedContents($request->files->get('csv_file'));
            $agencies = $importAgencyCsv->preview($contents);
            $token = bin2hex(random_bytes(32));
            $replace = $request->request->getBoolean('replace');
            $request->getSession()->set(self::PREVIEW_SESSION_KEY, [
                'contents' => base64_encode($contents),
                'token' => $token,
                'replace' => $replace,
            ]);

            return ['agencies' => $agencies, 'token' => $token, 'replace' => $replace];
        } catch (CsvFormatException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return null;
        }
    }

    private function execute(Request $request, ImportAgencyCsv $importAgencyCsv): void
    {
        if (!$this->isCsrfTokenValid('agency_csv_execute', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRFトークンが不正です。');
        }

        $storedPreview = $request->getSession()->remove(self::PREVIEW_SESSION_KEY);
        if (!is_array($storedPreview)
            || !is_string($storedPreview['contents'] ?? null)
            || !is_string($storedPreview['token'] ?? null)
            || !is_bool($storedPreview['replace'] ?? null)
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
            $importAgencyCsv->execute($contents, $storedPreview['replace']);
            $this->addFlash('success', '所属区分CSVを反映しました。');
        } catch (CsvFormatException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
    }

    /** @param array{agencies: list<Agency>, token: string, replace: bool}|null $preview */
    private function renderPage(AgencyRepository $agencies, ?array $preview): Response
    {
        $contentSecurityPolicyNonce = base64_encode(random_bytes(18));
        $response = $this->render('admin/agency_csv.html.twig', [
            'agencies' => $agencies->findAll(),
            'preview' => $preview,
            'content_security_policy_nonce' => $contentSecurityPolicyNonce,
            'preview_status' => 'CSVはプレビュー後、確認操作を行った場合だけ反映されます。',
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'nonce-{$contentSecurityPolicyNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
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
}
