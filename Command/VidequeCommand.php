<?php
namespace Sergeym\VidequeBundle\Command;

use Application\Sonata\MediaBundle\Entity\Media;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\X264;
use Sergeym\VidequeBundle\Format\Video\X264SinglePass;
use Sergeym\VidequeBundle\Provider\VidequeProvider;
use Sonata\MediaBundle\Model\MediaInterface;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Mmoreram\RSQueueBundle\Command\ConsumerCommand;
use FFMpeg;

/**
 * Video queue command
 */
class VidequeCommand extends ConsumerCommand
{

    /**
     * Configuration method
     */
    protected function configure()
    {
        $this
            ->setName('videque:consumer')
            ->setDescription('Fill media with metadata, start encoding for additional formats, create thumbnails');
        ;

        parent::configure();
    }

    /**
     * Relates queue name with appropiated method
     */
    public function define()
    {
        $this->addQueue(VidequeProvider::QUEUE_NAME, 'consumeVideo');
    }

    /**
     * If many queues are defined, as Redis respects order of queues, you can shuffle them
     * just overwritting method shuffleQueues() and returning true
     *
     * @return boolean Shuffle before passing to Gearman
     */
    public function shuffleQueues()
    {
        return true;
    }

    /**
     * Consume method with retrieved queue value
     *
     * @param InputInterface  $input   An InputInterface instance
     * @param OutputInterface $output  An OutputInterface instance
     * @param Mixed           $payload Data retrieved and unserialized from queue
     */
    protected function consumeVideo(InputInterface $input, OutputInterface $output, $payload)
    {
        if (isset($payload['id']) && $id = $payload['id']) {
            /** @var Media $media */
            if ($media = $this->getMediaById($id)) {
                $output->writeln(sprintf('Processing media with ID=%d', $id));

                $video = $this->getFFMpeg()->open($this->getProvider($media)->getReferenceFilePath($media));

                if ($video->getStreams()->videos()->count()>0)
                {
                    $videoParams = $video->getStreams()->videos()->first();

                    $media->setWidth($videoParams->getDimensions()->getWidth());
                    $media->setHeight($videoParams->getDimensions()->getHeight());

                    $screenshotAt = $media->getMetadataValue(VidequeProvider::FIELD_NAME_SCREENSHOT_AT);
                    $duration = intval($video->getFormat()->get('duration'));

                    if ($screenshotAt == 0 || $duration<=$screenshotAt) {
                        if ($video->getFormat()->get('duration') > 0) {
                            $screenshotAt = floatval($video->getFormat()->get('duration')) / 2;
                        }
                    }

                    // reference screenshot
                    $screenshotPath = $this->getProvider($media)->generateThumbPath($media, 'reference');
                    $output->writeln(sprintf('  Creating screenshot to %s', $screenshotPath));

                    $video
                        ->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($screenshotAt))
                        ->save($screenshotPath);

                    $provider = $this->getProvider($media);
                    $formatList = $provider->getFormats();

                    try {
                        foreach ($formatList as $format => $params) {

                            if ($format !== 'admin') {
                                $_w = $params['width'];
                                $_h = $params['height'];

                                $ratio = $videoParams->getDimensions()->getRatio();

                                if ($_w and !$_h) {
                                    $_h = $ratio->calculateHeight($_w);
                                } elseif (!$_w and $_h) {
                                    $_w = $ratio->calculateWidth($_h);
                                }

                                // resize video
                                $video
                                    ->filters()
                                    ->resize(new Dimension($_w, $_h), ResizeFilter::RESIZEMODE_INSET)
                                    ->synchronize();

                                $savePath = $this->generatePath($media, $format);
                                $output->writeln(sprintf('  Resizing media according format %s to %dx%s to %s ...', $format, $_w, $_h, $savePath));
                                //$video->save(new X264($this->getContainer()->getParameter('videque.codec.audio'), $this->getContainer()->getParameter('videque.codec.video')), $savePath);
                                $video->save(new X264SinglePass($this->getContainer()->getParameter('videque.codec.audio'), $this->getContainer()->getParameter('videque.codec.video')), $savePath);

                                // screnshoot generation
                                $screenshotPath = $this->getProvider($media)->generateThumbPath($media, $format);
                                $output->writeln(sprintf('  Creating thumb %dx%s to %s ...', $_w, $_h, $screenshotPath));
                                $video
                                    ->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($screenshotAt))
                                    ->save($screenshotPath);
                            }
                        }

                        $media->setProviderStatus(MediaInterface::STATUS_OK);
                        $this->saveMedia($media);

                    } catch (\Exception $e) {
                        $this->logger()->error(sprintf('Error occurred during processing media with id=%d: %s', $id, $e->getMessage()));
                    }

                    $output->writeln('Completed.');
                    $output->writeln('');
                } else {
                    $this->logger()->error(sprintf('No video stream found in media with id %d',$id));
                }
            }
        }
    }

    /**
     * @return Logger
     */
    public function logger()
    {
        return $this->getContainer()->get('logger');
    }


    /**
     * @param $id
     * @return Media
     */
    protected function getMediaById($id)
    {
        $manager = $this->getManager();
        return $manager->find(Media::class, $id);
    }

    private function getFFMpeg()
    {
        return $this->getContainer()->get('php_ffmpeg.ffmpeg');
    }

    /**
     * @param Media $media
     * @return VidequeProvider
     */
    private function getProvider(Media $media)
    {
        return $this->getContainer()->get($media->getProviderName());
    }

    private function generatePath(Media $media, $format) {
        return sprintf('%s/%s',
            $this->getProvider($media)->getFilesystem()->getAdapter()->getDirectory(),
            $this->getProvider($media)->generateFilePath($media, $format)
        );
    }

    /**
     * @param Media $media
     */
    private function saveMedia(Media $media)
    {
        $manager = $this->getManager();
        $manager->persist($media);
        $manager->flush();
    }

    /**
     * @return \Doctrine\Common\Persistence\ObjectManager
     */
    private function getManager()
    {
        return $this->getContainer()->get('doctrine')->getManager();
    }

}