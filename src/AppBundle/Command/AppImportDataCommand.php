<?php

namespace AppBundle\Command;

use AppBundle\Entity\District;
use AppBundle\Entity\LivingPlace;
use AppBundle\Entity\Station;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppImportDataCommand extends ContainerAwareCommand
{

    private $livingPlaceSchema = [
        'codact' => "setActivityCode",
        'xy' => 'setCoordinates',
        'arro' => 'setArr',
        'adresse_complete' => 'setAddress',
        'libact' => 'setActivityLabel',
        'type_voie' => 'setSituation',
        'surface' => 'setArea'
    ];

    private $stationSchema = [
        'departement' => 'setDepartement',
        'code_postal' => 'setZipCode',
        'coord' => 'setCoordinates',
        'stop_id' => 'setStopId',
        'stop_desc' => 'setDescription',
        'stop_name' => 'setName'
    ];

    private $districtSchema = [
        'geo_point_2d' => 'setGeoPoint',
        'typ_iris' => 'setTypIris',
        'p12_pop' => 'setP12Pop',
        'denspop12' => 'setDensPop12',
        'p12_h0014' => 'setPop12H0014',
        'p12_h1529' => 'setP12H1529',
        'p12_h3044' => 'setP12H3044',
        'p12_h4559' => 'setP12H4559',
        'p12_h6074' => 'setP12H6074',
        'p12_h75p' => 'setP12H75p',
        'p12_pop60p' => 'setP12Pop60',
        'p12_pop001' => 'setP12Pop001'
    ];

    /**
     * @var ContainerInterface
     */
    private $em;

    /**
     * @var Client
     */
    private $client;

    public function __construct(EntityManager $em)
    {
        parent::__construct();
        $this->em = $em;
        $this->client = new Client();
    }

    protected function configure()
    {
        $this
            ->setName('app:import-data')
            ->setDescription('Import data from many public API')
        ;
    }

    private function createEntities(string $apiUri, $entity, array $model, OutputInterface $output)
    {
        @ini_set('memory_limit', -1);
        $output->writeln([
            PHP_EOL,
            'Create ' . $entity . ' entities from JSON export.',
            '<comment>Downloading JSON file ...</comment>'
        ]);
        $response = $this->decode($this->client->get($apiUri));
        $output->write('<info>Downloaded.</info>', ['newline' => true]);
        $chunkSize = 500;
        $index = 0;
        $progressBar = new ProgressBar($output, count($response));
        $output->write('<comment>Starting to generate entities.</comment>', ['newline' => true]);
        $progressBar->start();
        foreach ($response as $itemData) {
            $newEntity = $this->setDataByModel($model, $itemData['fields'], new $entity());
            $this->em->persist($newEntity);
            $index++;
            $progressBar->advance();
            if (($index % $chunkSize) == 0) {
                $progressBar->setMessage(PHP_EOL.'Flushing 500 entities.');
                $this->em->flush();
                $this->em->clear();
                $progressBar->setMessage('Keep going.');
            }
        }
        $progressBar->finish();
        $output->write(PHP_EOL.'Flushing last entities ...', ['newline' => true]);
        $this->em->flush();
        $this->em->clear();
        $output->write('<info>Done with success.</info>', ['newline' => true]);
        unset($response);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->em->getConnection()->query('TRUNCATE TABLE living_place')->execute();
        $this->em->getConnection()->query('TRUNCATE TABLE station')->execute();
        $this->em->getConnection()->query('TRUNCATE TABLE district')->execute();

        // Create Districts from json export.
        $this->createEntities(
            'https://public.opendatasoft.com/explore/dataset/iris-demographie/download/?format=json&timezone=Europe/Berlin',
            District::class,
            $this->districtSchema,
            $output
        );

        // Create Living Places from json export.
        $this->createEntities(
            'https://opendata.paris.fr/explore/dataset/commercesparis/download/?format=json&timezone=Europe/Berlin',
            LivingPlace::class,
            $this->livingPlaceSchema,
            $output
        );

        // Create Stations from json export.
        $this->createEntities(
            'https://data.ratp.fr/explore/dataset/positions-geographiques-des-stations-du-reseau-ratp/download/?format=json&timezone=Europe/Berlin',
            Station::class,
            $this->stationSchema,
            $output
        );



    }

    private function decode(ResponseInterface $response)
    {
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param array $model
     * @param array $data
     * @param $entity
     * @return mixed
     */
    private function setDataByModel(array $model, array $data, $entity)
    {
        foreach ($model as $key => $function) {
            if (array_key_exists($key, $data)) {
                $entity->set($function, $data[$key]);
            }
        }

        return $entity;
    }

}