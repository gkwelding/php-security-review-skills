<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    #[Route('/products', name: 'app_products', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): Response
    {
        return $this->render('product/index.html.twig', [
            'products' => $products->search(
                $request->query->getString('q'),
                $request->query->getString('category'),
                $request->query->getString('price', 'ASC'),
            ),
        ]);
    }

    #[Route('/products/{id}', name: 'app_product', methods: ['GET'])]
    public function show(Product $product): Response
    {
        return $this->render('product/show.html.twig', [
            'product' => $product,
            'product_data' => [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => $product->getPriceInPence(),
            ],
        ]);
    }
}
