<?php

namespace App\Service;

use App\Repository\OrderRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

class ReportExporter
{
    public function __construct(
        private OrderRepository $orders,
        #[Autowire('%kernel.project_dir%/var/reports')]
        private string $dir,
    ) {
    }

    public function export(string $name): string
    {
        $csv = fopen($this->dir.'/orders.csv', 'w');
        foreach ($this->orders->findAll() as $order) {
            fputcsv($csv, [$order->getId(), $order->getStatus(), $order->getTotalInPence()], escape: '');
        }
        fclose($csv);

        $archive = $this->dir.'/'.$name.'.zip';
        Process::fromShellCommandline('zip -j '.$archive.' '.$this->dir.'/orders.csv')->mustRun();

        return $archive;
    }
}
