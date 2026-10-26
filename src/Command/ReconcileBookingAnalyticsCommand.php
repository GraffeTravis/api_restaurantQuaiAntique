<?php

namespace App\Command;

use App\Repository\BookingAnalyticsRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:analytics:reconcile', description: 'Importe/réconcilie les réservations et rejoue les marqueurs de suppression vers MongoDB.')]
final class ReconcileBookingAnalyticsCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly BookingAnalyticsRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->repository->ensureIndexes();
        $count = $this->connection->transactional(function (Connection $connection): int {
            // Prevent an older backfill snapshot from overtaking a concurrent booking mutation.
            $connection->executeStatement('LOCK TABLE booking IN SHARE ROW EXCLUSIVE MODE');
            $connection->executeQuery('SELECT project_booking_analytics(b) FROM booking b')->free();
            $connection->executeStatement(<<<'SQL'
                UPDATE booking_analytics_state SET snapshot = snapshot || jsonb_build_object(
                    'deleted', true, 'version', nextval('booking_analytics_version_seq'))
                WHERE NOT (snapshot->>'deleted')::boolean
                    AND NOT EXISTS (SELECT 1 FROM booking WHERE id = booking_id)
                SQL);
            $connection->executeQuery("SELECT enqueue_booking_analytics(snapshot) FROM booking_analytics_state WHERE (snapshot->>'deleted')::boolean")->free();

            return (int) $connection->fetchOne('SELECT count(*) FROM booking_analytics_state');
        });
        $output->writeln(sprintf('%d réservations/marqueurs mis en file. Exécuter messenger:consume analytics pour terminer la synchronisation.', $count));

        return Command::SUCCESS;
    }
}
