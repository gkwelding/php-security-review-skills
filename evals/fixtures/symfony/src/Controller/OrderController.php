<?php

namespace App\Controller;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/orders')]
class OrderController extends AbstractController
{
    #[Route('/{id}/receipt', name: 'app_order_receipt', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function receipt(Order $order): Response
    {
        return $this->render('order/receipt.html.twig', ['order' => $order]);
    }

    #[Route('/{id}/cancel', name: 'app_order_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, OrderRepository $orders, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cancel-order', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $order = $orders->findOneBy(['id' => $id, 'customer' => $this->getUser()])
            ?? throw $this->createNotFoundException();

        $order->cancel();
        $em->flush();

        return $this->redirectToRoute('app_account');
    }
}
