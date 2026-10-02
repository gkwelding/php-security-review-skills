<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Service\ReportExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/manage')]
class ManageController extends AbstractController
{
    #[Route('/customers', name: 'app_manage_customers', methods: ['GET'])]
    public function customers(EntityManagerInterface $em): Response
    {
        return $this->render('manage/customers.html.twig', [
            'customers' => $em->getRepository(User::class)->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/products/{id}/image', name: 'app_manage_product_image', methods: ['POST'])]
    public function importImage(Product $product, Request $request, HttpClientInterface $httpClient): Response
    {
        if (!$this->isCsrfTokenValid('manage', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $image = $httpClient->request('GET', $request->request->getString('image_url'))->getContent();
        file_put_contents($this->getParameter('kernel.project_dir').'/public/images/products/'.$product->getId().'.jpg', $image);

        return $this->redirectToRoute('app_product', ['id' => $product->getId()]);
    }

    #[Route('/reports', name: 'app_manage_report', methods: ['POST'])]
    public function report(Request $request, ReportExporter $exporter): Response
    {
        if (!$this->isCsrfTokenValid('manage', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $this->file($exporter->export($request->request->getString('name', 'orders')));
    }
}
