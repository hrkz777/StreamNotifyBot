<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Csv\AgencyCsvCodec;
use App\Application\Csv\CsvExportEncoding;
use App\Application\Csv\CsvFormatException;
use App\Domain\Catalog\AgencyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/agencies/csv/export', name: 'admin_agencies_csv_export', methods: ['GET'])]
final class AgencyCsvExportController extends AbstractController
{
    public function __invoke(Request $request, AgencyRepository $agencies, AgencyCsvCodec $agencyCsvCodec): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        try {
            $encoding = CsvExportEncoding::fromRequestValue($request->query->getString('encoding', CsvExportEncoding::Utf8->value));
            $contents = $agencyCsvCodec->export($agencies->findAll(), $encoding);
        } catch (CsvFormatException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_agencies_csv');
        }

        $response = new Response($contents);
        $response->headers->set('Content-Type', sprintf('text/csv; charset=%s', $encoding->charset()));
        $response->headers->set('Content-Disposition', 'attachment; filename="agencies.csv"');
        $response->headers->set('Content-Length', (string) strlen($contents));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
