<?php

namespace App\Entity;

use App\Repository\RestaurantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RestaurantRepository::class)]
class Restaurant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank(message: 'Le nom du restaurant est obligatoire')]
    #[Assert\Length(
        min: 1,
        max: 32,
        minMessage: 'Le nom doit contenir au moins 1 caractère',
        maxMessage: 'Le nom ne doit pas dépasser 32 caractères'
    )]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description est obligatoire')]
    private ?string $description = null;

    #[ORM\Column]
    private array $amOpeningTime = [];

    #[ORM\Column]
    private array $pmOpeningTime = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updateAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Picture>
     */
    #[ORM\OneToMany(targetEntity: Picture::class, mappedBy: 'restaurant', orphanRemoval: true)]
    private Collection $pictures;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull(message: 'Le nombre maximal de couverts est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de couverts doit être supérieur à 0')]
    private ?int $maxGuest = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'restaurant')]
    #[ORM\JoinColumn(name: 'owner', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: 'Le propriétaire du restaurant est obligatoire')]
    private ?User $owner = null;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'restaurant', orphanRemoval: true)]
    private Collection $bookings;

    /**
     * @var Collection<int, Menu>
     */
    #[ORM\OneToMany(targetEntity: Menu::class, mappedBy: 'restaurant', orphanRemoval: true)]
    private Collection $menus;

    public function __construct()
    {
        $this->pictures = new ArrayCollection();
        $this->bookings = new ArrayCollection();
        $this->menus = new ArrayCollection();
    }

    // ==================== Getters & Setters ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getAmOpeningTime(): array
    {
        return $this->amOpeningTime;
    }

    public function setAmOpeningTime(array $amOpeningTime): static
    {
        $this->amOpeningTime = $amOpeningTime;
        return $this;
    }

    public function getPmOpeningTime(): array
    {
        return $this->pmOpeningTime;
    }

    public function setPmOpeningTime(array $pmOpeningTime): static
    {
        $this->pmOpeningTime = $pmOpeningTime;
        return $this;
    }

    public function getUpdateAt(): ?\DateTimeImmutable
    {
        return $this->updateAt;
    }

    public function setUpdateAt(?\DateTimeImmutable $updateAt): static
    {
        $this->updateAt = $updateAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getMaxGuest(): ?int
    {
        return $this->maxGuest;
    }

    public function setMaxGuest(int $maxGuest): static
    {
        $this->maxGuest = $maxGuest;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    // ==================== Pictures ====================

    /**
     * @return Collection<int, Picture>
     */
    public function getPictures(): Collection
    {
        return $this->pictures;
    }

    public function addPicture(Picture $picture): static
    {
        if (!$this->pictures->contains($picture)) {
            $this->pictures->add($picture);
            $picture->setRestaurant($this);
        }

        return $this;
    }

    public function removePicture(Picture $picture): static
    {
        if ($this->pictures->removeElement($picture)) {
            if ($picture->getRestaurant() === $this) {
                $picture->setRestaurant(null);
            }
        }

        return $this;
    }

    // ==================== Bookings ====================

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    public function addBooking(Booking $booking): static
    {
        if (!$this->bookings->contains($booking)) {
            $this->bookings->add($booking);
            $booking->setRestaurant($this);
        }

        return $this;
    }

    public function removeBooking(Booking $booking): static
    {
        if ($this->bookings->removeElement($booking)) {
            if ($booking->getRestaurant() === $this) {
                $booking->setRestaurant(null);
            }
        }

        return $this;
    }

    /**
     * Obtenir le nombre total de couverts réservés pour une date/heure donnée.
     *
     * @param \DateTimeImmutable $date
     * @param string $hour Format: HH:mm
     *
     * @return int
     */
    public function getBookedGuestsForSlot(\DateTimeImmutable $date, string $hour): int
    {
        $totalGuests = 0;

        foreach ($this->bookings as $booking) {
            if (
                $booking->getOrderDate() == $date &&
                $booking->getOrderHour()->format('H:i') === $hour
            ) {
                $totalGuests += $booking->getGuestNumber();
            }
        }

        return $totalGuests;
    }

    /**
     * Vérifier si le restaurant a une disponibilité pour un nombre de couverts.
     *
     * @param int $guestNumber
     * @param \DateTimeImmutable $date
     * @param string $hour Format: HH:mm
     *
     * @return bool
     */
    public function hasAvailability(int $guestNumber, \DateTimeImmutable $date, string $hour): bool
    {
        $bookedGuests = $this->getBookedGuestsForSlot($date, $hour);
        return ($bookedGuests + $guestNumber) <= $this->maxGuest;
    }

    // ==================== Menus ====================

    /**
     * @return Collection<int, Menu>
     */
    public function getMenus(): Collection
    {
        return $this->menus;
    }

    public function addMenu(Menu $menu): static
    {
        if (!$this->menus->contains($menu)) {
            $this->menus->add($menu);
            $menu->setRestaurant($this);
        }

        return $this;
    }

    public function removeMenu(Menu $menu): static
    {
        if ($this->menus->removeElement($menu)) {
            if ($menu->getRestaurant() === $this) {
                $menu->setRestaurant(null);
            }
        }

        return $this;
    }
}