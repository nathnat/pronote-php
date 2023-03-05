<?php

namespace Pronote;

abstract class DataClass
{
    public function __construct(array $data = [])
    {
        $this->hydrate($data);
    }

    public function hydrate(array $data)
    {
        foreach ($data as $key => $value) {
            // Si l'attribut existe
            if (property_exists($this, $key))
                $this->$key = $value;
        }
    }
}