<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\CsvExportEncoding;
use App\Application\Csv\NotificationRouteCsvCodec;
use App\Domain\Notification\NotificationRouteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationRouteCsvManagementController extends AbstractController
{
    #[Route('/admin/notifications/csv', name: 'admin_notifications_csv', methods: ['GET'])]
    public function index(NotificationRouteRepository $routes): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        return $this->page('admin/notification_route_csv.html.twig', ['routes' => $routes->findAll()]);
    }

    #[Route('/admin/notifications/csv/export', name: 'admin_notifications_csv_export', methods: ['GET'])]
    public function export(Request $request, NotificationRouteRepository $routes, NotificationRouteCsvCodec $csvCodec): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        try {
            $encoding = CsvExportEncoding::fromRequestValue($request->query->getString('encoding', CsvExportEncoding::Utf8->value));
            $contents = $csvCodec->export($routes->findAll(), $encoding);
        } catch (CsvFormatException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_notifications');
        }

        $response = new Response($contents);
        $response->headers->set('Content-Type', sprintf('text/csv; charset=%s', $encoding->charset()));
        $response->headers->set('Content-Disposition', 'attachment; filename="notification-routes.csv"');
        $response->headers->set('Content-Length', (string) strlen($contents));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** @param array<string, mixed> $parameters */
    private function page(string $template, array $parameters): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $response = $this->render($template, $parameters + [
            'content_security_policy_nonce' => $nonce,
            'preview_status' => 'CSVはShift_JIS形式でエクスポートできます。通知設定の保存処理と一括更新は後続の機能として追加予定です。',
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
