<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\StreamerCsvCodec;
use App\Domain\Catalog\StreamerCatalogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StreamerCsvManagementController extends AbstractController
{
    #[Route('/admin/streamers/csv', name: 'admin_streamers_csv', methods: ['GET'])]
    public function index(StreamerCatalogRepository $streamers): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        $accountsByStreamer = [];
        foreach ($streamers->findAllPlatformAccounts() as $account) {
            $accountsByStreamer[$account->streamerId][] = $account;
        }

        return $this->page('admin/streamer_csv.html.twig', [
            'streamers' => $streamers->findAllStreamers(),
            'accounts_by_streamer' => $accountsByStreamer,
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

    /** @param array<string, mixed> $parameters */
    private function page(string $template, array $parameters): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $response = $this->render($template, $parameters + [
            'content_security_policy_nonce' => $nonce,
            'preview_status' => 'CSVはShift_JIS形式でエクスポートできます。インポートと一括更新は後続の機能として追加予定です。',
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
