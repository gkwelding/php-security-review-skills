<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MemberController extends AbstractController
{
    #[Route('/members/{id}', name: 'app_member', methods: ['GET'])]
    public function show(User $member): Response
    {
        return $this->render('member/show.html.twig', ['member' => $member]);
    }

    #[Route('/api/members/{id}', name: 'app_api_member', methods: ['GET'])]
    public function showJson(User $member): Response
    {
        return $this->json($member);
    }
}
