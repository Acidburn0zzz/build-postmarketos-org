<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\PackageDependencyRepository")
 */
class PackageDependency
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Package", inversedBy="packageDependencies")
     * @ORM\JoinColumn(nullable=false)
     */
    private $package;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Package")
     * @ORM\JoinColumn(nullable=false)
     */
    private $requirement;

    public function getId()
    {
        return $this->id;
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function setPackage(Package $package): self
    {
        $this->package = $package;

        return $this;
    }

    /**
     * @return Package
     */
    public function getRequirement()
    {
        return $this->requirement;
    }

    public function setRequirement(Package $requirement)
    {
        $this->requirement = $requirement;

        return $this;
    }
}
