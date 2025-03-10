<?php

declare(strict_types=1);

namespace Api\Models;

use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\Timestampable;

class Tool extends Model
{
    // Column constants
    public const ID = 'id';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const MATERIAL = 'material';
    public const INVENTOR = 'inventor';
    public const YEAR = 'year';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    // Primary identification
    public ?int $id = null;

    // Tool information
    public string $name;
    public string $description;
    public ?string $material = null;
    public ?string $inventor = null;
    public ?int $year = null;

    // Timestamps
    public string $created_at;
    public string $updated_at;

    /**
     * Initialize model relationships and behaviors
     */
    public function initialize(): void
    {
        $this->setSource('tool');

        // Add automatic timestamp behavior
        $this->addBehavior(
            new Timestampable([
                'beforeCreate' => [
                    'field' => 'created_at',
                    'format' => 'Y-m-d H:i:s'
                ],
                'beforeUpdate' => [
                    'field' => 'updated_at',
                    'format' => 'Y-m-d H:i:s'
                ]
            ])
        );
    }

    /**
     * Model validation
     */
    public function validation(): bool
    {
        $validator = new \Phalcon\Filter\Validation();

        // Required fields validation
        $validator->add(
            self::NAME,
            new \Phalcon\Filter\Validation\Validator\PresenceOf([
                'message' => 'Name is required'
            ])
        );

        $validator->add(
            self::DESCRIPTION,
            new \Phalcon\Filter\Validation\Validator\PresenceOf([
                'message' => 'Description is required'
            ])
        );

        // Year validation (if provided)
        if (!is_null($this->year)) {
            $validator->add(
                self::YEAR,
                new \Phalcon\Filter\Validation\Validator\Between([
                    'minimum' => 1,
                    'maximum' => date('Y'),
                    'message' => 'Year must be between 1 and the current year'
                ])
            );
        }

        return $this->validate($validator);
    }

    /**
     * Find tools by material
     */
    public static function findByMaterial(string $material): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'material LIKE :material:',
            'bind' => ['material' => "%$material%"],
            'bindTypes' => ['material' => \PDO::PARAM_STR],
            'order' => 'name ASC'
        ]);
    }

    /**
     * Find tools by inventor
     */
    public static function findByInventor(string $inventor): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'inventor LIKE :inventor:',
            'bind' => ['inventor' => "%$inventor%"],
            'bindTypes' => ['inventor' => \PDO::PARAM_STR],
            'order' => 'name ASC'
        ]);
    }

    /**
     * Find tools by year range
     */
    public static function findByYearRange(int $startYear, int $endYear): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'year >= :start_year: AND year <= :end_year:',
            'bind' => ['start_year' => $startYear, 'end_year' => $endYear],
            'bindTypes' => ['start_year' => \PDO::PARAM_INT, 'end_year' => \PDO::PARAM_INT],
            'order' => 'year ASC'
        ]);
    }

    /**
     * Search tools by name or description
     */
    public static function search(string $query): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return self::find([
            'conditions' => 'name LIKE :query: OR description LIKE :query:',
            'bind' => ['query' => "%$query%"],
            'bindTypes' => ['query' => \PDO::PARAM_STR],
            'order' => 'name ASC'
        ]);
    }
}