<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Restaurant;
use App\Service\ServiceSchedule;
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
     * pour le service contenant l'heure demandée.
     */
    public function getTotalGuestsForSlot(
        Restaurant $restaurant,
        DateTime $date,
        DateTime $hour,
        ?Booking $excludedBooking = null
    ): int {
        $range = ServiceSchedule::rangeAt($restaurant, $hour);
        if ($range === null) {
            return 0;
        }
        $queryBuilder = $this->createQueryBuilder('b')
            ->select('COALESCE(SUM(b.guestNumber), 0)')
            ->andWhere('b.restaurant = :restaurant')
            ->andWhere('b.orderDate = :date')
            ->andWhere('b.orderHour BETWEEN :start AND :end')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('date', $date)
            ->setParameter('start', DateTime::createFromFormat('!H:i', $range[0]))
            ->setParameter('end', DateTime::createFromFormat('!H:i', $range[1]));

        if ($excludedBooking?->getId() !== null) {
            $queryBuilder
                ->andWhere('b != :excludedBooking')
                ->setParameter('excludedBooking', $excludedBooking);
        }

        $result = $queryBuilder
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
            ->andWhere('b.restaurant = :restaurant')
            ->andWhere('b.orderDate = :date')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('date', $date)
            ->orderBy('b.orderHour', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
