<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use Doctrine\ORM\EntityManagerInterface;
use MastodonAPI;
use App\Entity\Media;
use App\Entity\Post;

class MastodonCommand extends Command
{
    protected static $defaultName = 'post:publish';
    protected static $defaultDescription = 'Publish a status to a mastodon account';
    private $entityManager;
    private $io;
    
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many status to post each time', -1)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        
        $repository = $this->entityManager->getRepository("App:Post");
        $count = 0;
        
        $posts = $repository->findAllWaiting($input->getOption('limit'));
        if($posts) {
            foreach($posts as $post) {
                $data = $this->publish($post);
                $count++;
                $post->setExternalUrl($data['url']);
                $post->setPublishedAt(new \DateTime);
                $this->entityManager->persist($post);
                $this->entityManager->flush();
            }
        }
        
        $this->io->success(sprintf('%s status published', $count));

        return Command::SUCCESS;
    }
    
    private function publish(Post $post) {
        $repository = $this->entityManager->getRepository("App:Account");
        $account    = $repository->findOneBy(['active' => true]);
        $mastodon   = new MastodonAPI($account->getToken(), $account->getInstanceUrl());
        
        $media_ids  = []; 
        
        foreach($post->getMedia() as $m) {
            $response = $this->processMedia($m, $mastodon);
            if($response) {
                $media_ids = $response['id'];
            }
        }
        
        $status = $post->getStatus();
        foreach($post->getTags() as $t) {
            $status .= ' '.$t->getName();
        }
        
        $status_data = [
            'status'      => $status,
            'visibility'  => 'public',
            'language'    => 'pt',
            'media_ids[]' => $media_ids,
        ];

        $data = $mastodon->postStatus($status_data);
        
        $this->io->info(sprintf('Created %s', $data['url']));
        
        return $data;
    }
    
    private function processMedia(Media $media, MastodonAPI $mastodon) {
        $this->io->note(sprintf('Downloading %s', $media->getUrl()));
        $filename   = uniqid();
        $tmpfile    = sys_get_temp_dir().'/'. $filename;
        file_put_contents($tmpfile, file_get_contents($media->getUrl()));
        $finfo      = new \finfo(FILEINFO_MIME_TYPE);
        $info       = $finfo->file($tmpfile);
        $ext        = '.jpg';
        if(strpos($info, '/gif') !== false) {
            $ext = '.gif';
        }
        elseif(strpos($info, '/png') !== false) {
            $ext = '.png';
        }
        
        $curl_file = curl_file_create($tmpfile, $info, $filename.$ext);
        $body = [
            'file' => $curl_file,
        ];

        $response = $mastodon->uploadMedia($body);
        if(isset($response['error'])) {
            $this->io->error($response['error']);
            return false;
        }
        return $response;
    }
}
