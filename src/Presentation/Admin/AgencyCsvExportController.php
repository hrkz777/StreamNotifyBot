<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Csv\AgencyCsvCodec;
use App\Domain\Catalog\AgencyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/agencies/csv/export', name: 'admin_agencies_csv_export', methods: ['GET'])]
final class AgencyCsvExportController extends AbstractController
{
    public function __invoke(AgencyRepository $agencies, AgencyCsvCodec $agencyCsvCodec): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        $contents = $agencyCsvCodec->export($agencies->findAll());
        $response = new Response($contents);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="agencies.csv"');
        $response->headers->set('Content-Length', (string) strlen($contents));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
