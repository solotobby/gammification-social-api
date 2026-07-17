<?php

namespace App\Services\Media\Format;

use FFMpeg\Format\Video\WebM;

class WebMOpus extends WebM
{
    public function getAvailableAudioCodecs(): array
    {
        return ['libvorbis', 'libopus', 'copy'];
    }
}