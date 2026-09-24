<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sdp_symfony_notes')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $body = '';
}
