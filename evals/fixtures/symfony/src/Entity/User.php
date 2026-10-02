<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 80)]
    private string $displayName = '';

    #[ORM\Column(type: 'text')]
    private string $bio = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $apiToken = null;

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array { return array_values(array_unique([...$this->roles, 'ROLE_USER'])); }
    /** @param list<string> $roles */
    public function setRoles(array $roles): void { $this->roles = $roles; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }

    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): void { $this->displayName = $displayName; }

    public function getBio(): string { return $this->bio; }
    public function setBio(string $bio): void { $this->bio = $bio; }

    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): void { $this->street = $street; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): void { $this->city = $city; }

    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $postcode): void { $this->postcode = $postcode; }

    public function getApiToken(): ?string { return $this->apiToken; }
    public function setApiToken(?string $apiToken): void { $this->apiToken = $apiToken; }
}
