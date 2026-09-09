<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Restaurant;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Retourne le nombre total de couverts réservés
     * pour un restaurant, une date et une heure données.
     */
    public function getTotalGuestsForSlot(
        Restaurant $restaurant,
        DateTime $date,
        DateTime $hour
    ): int {
        $result = $this->createQueryBuilder('b')
            ->select('COALESCE(SUM(b.guestNumber), 0)')
            ->andWhere('b.Restaurant = :restaurant')
            ->andWhere('b.orderDate = :date')
            ->andWhere('b.orderHour = :hour')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('date', $date)
            ->setParameter('hour', $hour)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Retourne toutes les réservations d'un restaurant
     * pour une date donnée.
     */
    public function findByRestaurantAndDate(
        Restaurant $restaurant,
        DateTime $date
    ): array {
        return $this->createQueryBuilder('b')
            ->andWhere('b.Restaurant = :restaurant')
            ->andWhere('b.orderDate = :date')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('date', $date)
            ->orderBy('b.orderHour', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

