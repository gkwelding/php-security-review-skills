<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\AddressType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/account')]
class AccountController extends AbstractController
{
    #[Route('', name: 'app_account', methods: ['GET', 'POST'])]
    public function address(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(AddressType::class, $this->getUser());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/address.html.twig', ['form' => $form]);
    }

    #[Route('', name: 'app_account_update', methods: ['PATCH'])]
    public function update(Request $request, SerializerInterface $serializer, EntityManagerInterface $em): Response
    {
        if ($request->getContentTypeFormat() !== 'json') {
            throw new UnsupportedMediaTypeHttpException();
        }

        $serializer->deserialize($request->getContent(), User::class, 'json', [
            AbstractNormalizer::OBJECT_TO_POPULATE => $this->getUser(),
        ]);
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
