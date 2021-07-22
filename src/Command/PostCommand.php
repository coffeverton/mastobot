<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use App\Entity\Post;
use App\Entity\Media;
use App\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;

class PostCommand extends Command
{
    protected static $defaultName = 'post:new';
    protected static $defaultDescription = 'Create a new status that will later be sent to Mastodon';
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addArgument('status', InputArgument::REQUIRED, 'The status text')
            ->addArgument('media', InputArgument::OPTIONAL, 'A list of urls, separated by a space')
            ->addArgument('tags', InputArgument::OPTIONAL, 'A list of hashtags, separated by a space')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $status     = $input->getArgument('status');
        $medialist  = $input->getArgument('media');
        $taglist    = $input->getArgument('tags');

        $post = new Post;
        $post->setStatus($status);
        $post->setCreatedAt(new \DateTime);
        $this->entityManager->persist($post);
        
        if($medialist) {
            $this->setMediaList($medialist, $post);
        }
        
        if($taglist) {
            $this->setTagList($taglist, $post);
        }
        
        $this->entityManager->flush();

        $io->success('Status scheduled!');

        return Command::SUCCESS;
    }
    
    private function setMediaList($medialist, $post) {
        $arr = explode(' ', $medialist);
        foreach($arr as $m) {
            $media = new Media;
            $media->setUrl($m);
            $media->setPost($post);
            $this->entityManager->persist($media);
        }
    }
    
    private function setTagList($taglist, $post) {
        $arr = explode(' ', $taglist);
        $repository = $this->entityManager->getRepository("App:Tag");
        foreach($arr as $m) {
            
            $tag = $repository->findOneBy(['name' => '#'.$m]);
            
            if($tag === null) {
                $tag = new Tag;
                $tag->setName($m);
            }
            
            $tag->addPost($post);
            $this->entityManager->persist($tag);
        }
    }
}
