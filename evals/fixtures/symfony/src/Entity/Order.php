<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column]
    private int $totalInPence = 0;

    #[ORM\Column(length: 20)]
    private string $status = 'placed';

    #[ORM\Column(length: 255)]
    private string $deliveryAddress = '';

    public function __construct(User $customer)
    {
        $this->customer = $customer;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getTotalInPence(): int { return $this->totalInPence; }
    public function getStatus(): string { return $this->status; }
    public function getDeliveryAddress(): string { return $this->deliveryAddress; }

    public function cancel(): void
    {
        $this->status = 'cancelled';
    }
}
