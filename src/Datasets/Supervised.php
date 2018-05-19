<?php

namespace Rubix\Engine\Datasets;

use InvalidArgumentException;

class Supervised extends Dataset
{
    /**
     * The labeled outcomes for each sample in the dataset.
     *
     * @var array
     */
    protected $labels = [
        //
    ];

    /**
     * Factory method to create a supervised dataset from an array of datasets.
     *
     * @param  array  $datasets
     * @throws \InvalidArgumentException
     * @return self
     */
    public static function combine(array $datasets = []) : self
    {
        $samples = $labels = [];

        foreach ($datasets as $dataset) {
            if (!$dataset instanceof Supervised) {
                throw new InvalidArgumentException('Cannot merge any non-Supervised'
                    . ' datasets, ' . get_class($dataset) . ' found.');
            }

            $samples = array_merge($samples, $dataset->samples());
            $labels = array_merge($labels, $dataset->labels());
        }

        return new self($samples, $labels);
    }

    /**
     * @param  array  $samples
     * @param  array  $labels
     * @throws \InvalidArgumentException
     * @return void
     */
    public function __construct(array $samples, array $labels)
    {
        if (count($samples) !== count($labels)) {
            throw new InvalidArgumentException('The ratio of samples to labels'
             . ' must be equal.');
        }

        foreach ($labels as $label) {
            if (!is_string($label) && !is_numeric($label)) {
                throw new InvalidArgumentException('Label must be a string or'
                    . ' numeric type, ' . gettype($label) . ' found.');
            }
        }

        $this->labels = array_values($labels);

        parent::__construct($samples);
    }

    /**
     * @return array
     */
    public function labels() : array
    {
        return $this->labels;
    }

    /**
     * Return the label at a given index.
     *
     * @param  mixed  $index
     * @return mixed
     */
    public function outcome($index)
    {
        if (!isset($this->labels[$index])) {
            throw new RuntimeException('Label not found at the given index '
                . (string) $index . '.');
        }

        return $this->labels[$index];
    }

    /**
     * The set of all possible labeled outcomes.
     *
     * @return array
     */
    public function possibleOutcomes() : array
    {
        return array_values(array_unique($this->labels));
    }

    /**
     * Return a dataset containing only the first n samples.
     *
     * @param  int  $n
     * @return self
     */
    public function head(int $n = 10) : self
    {
        return new self(array_slice($this->samples, 0, $n),
            array_slice($this->labels, 0, $n));
    }

    /**
     * Return a dataset containing only the last n samples.
     *
     * @param  int  $n
     * @return self
     */
    public function tail(int $n = 10) : self
    {
        return new self(array_slice($this->samples, -$n),
            array_slice($this->labels, -$n));
    }

    /**
     * Take n samples and labels from this dataset and return them in a new
     * dataset.
     *
     * @param  int  $n
     * @throws \InvalidArgumentException
     * @return self
     */
    public function take(int $n = 1) : self
    {
        if ($n < 0) {
            throw new InvalidArgumentException('Cannot take less than 0 samples.');
        }

        return new self(array_splice($this->samples, 0, $n),
            array_splice($this->labels, 0, $n));
    }

    /**
     * Leave n samples and labels on this dataset and return the rest in a new
     * dataset.
     *
     * @param  int  $n
     * @throws \InvalidArgumentException
     * @return self
     */
    public function leave(int $n = 1) : self
    {
        if ($n < 0) {
            throw new InvalidArgumentException('Cannot leave less than 0 samples.');
        }

        return new self(array_splice($this->samples, $n),
            array_splice($this->labels, $n));
    }

    /**
     * Randomize the dataset.
     *
     * @return self
     */
    public function randomize() : self
    {
        $order = range(0, $this->numRows() - 1);

        shuffle($order);

        array_multisort($order, $this->samples, $this->labels);

        return $this;
    }

    /**
     * Split the dataset into two subsets with a given ratio of samples.
     *
     * @param  float  $ratio
     * @throws \InvalidArgumentException
     * @return array
     */
    public function split(float $ratio = 0.5) : array
    {
        if ($ratio <= 0 || $ratio >= 1) {
            throw new InvalidArgumentException('Split ratio must be strictly'
            . ' between 0 and 1.');
        }

        $n = round($ratio * $this->numRows());

        return [
            new self(array_slice($this->samples, 0, $n),
                array_slice($this->labels, 0, $n)),
            new self(array_slice($this->samples, $n),
                array_slice($this->labels, $n)),
        ];
    }

    /**
     * Split the dataset into two stratified subsets with a given ratio of samples.
     *
     * @param  float  $ratio
     * @throws \InvalidArgumentException
     * @return array
     */
    public function stratifiedSplit(float $ratio = 0.5) : array
    {
        if ($ratio <= 0 || $ratio >= 1) {
            throw new InvalidArgumentException('Split ratio must be strictly'
            . ' between 0 and 1.');
        }

        $left = $right = [[], []];

        foreach ($this->stratify() as $label => $stratum) {
            $n = round($ratio * count($stratum));

            $left[0] = array_merge($left[0], array_splice($stratum, 0, $n));
            $left[1] = array_merge($left[1], array_fill(0, $n, $label));

            $right[0] = array_merge($right[0], $stratum);
            $right[1] = array_merge($right[1], array_fill(0, count($stratum), $label));
        }

        return [
            new self(...$left),
            new self(...$right),
        ];
    }

    /**
     * Fold the dataset k - 1 times to form k equal size datasets.
     *
     * @param  int  $k
     * @throws \InvalidArgumentException
     * @return array
     */
    public function fold(int $k = 10) : array
    {
        if ($k < 2) {
            throw new InvalidArgumentException('Cannot fold the dataset less than'
            . '1 time.');
        }

        list($samples, $labels) = [$this->samples, $this->labels];

        $n = round(count($samples) / $k);

        $folds = [];

        for ($i = 0; $i < $k; $i++) {
            $folds[] = new self(array_splice($samples, 0, $n),
                array_splice($labels, 0, $n));
        }

        return $folds;
    }

    /**
     * Fold the dataset k - 1 times to form k equal size stratified datasets.
     *
     * @param  int  $k
     * @throws \InvalidArgumentException
     * @return array
     */
    public function stratifiedFold(int $k = 10) : array
    {
        if ($k < 2) {
            throw new InvalidArgumentException('Cannot fold the dataset less'
                . ' than 1 time.');
        }

        $folds = [];

        for ($i = 0; $i < $k; $i++) {
            $fold = [[], []];

            foreach ($this->stratify() as $label => $stratum) {
                $n = round(count($stratum) / $k);

                $fold[0] = array_merge($fold[0], array_splice($stratum, 0, $n));
                $fold[1] = array_merge($fold[1], array_fill(0, $n, $label));
            }

            $folds[] = new self(...$fold);
        }

        return $folds;
    }

    /**
     * Generate a collection of batches of size n from the dataset. If there are
     * not enough samples to fill an entire batch, then the dataset will contain
     * as many samples and labels as possible.
     *
     * @param  int  $n
     * @return array
     */
    public function batch(int $n = 50) : array
    {
        $batches = [];

        list($samples, $labels) = $this->all();

        while (!empty($samples)) {
            $batches[] = new self(array_splice($samples, 0, $n),
                array_splice($labels, 0, $n));
        }

        return $batches;
    }

    /**
     * Generate a random subset with replacement.
     *
     * @param  float  $ratio
     * @throws \InvalidArgumentException
     * @return self
     */
    public function randomSubsetWithReplacement(float $ratio = 0.1) : self
    {
        if ($ratio <= 0.0 || $ratio > 1.0) {
            throw new InvalidArgumentException('Sample ratio must be strictly'
            . ' between 0 and 1.');
        }

        $n = round($ratio * $this->numRows());

        $subset = [[], []];

        for ($i = 0; $i < $n; $i++) {
            $index = array_rand($this->samples);

            $subset[0][] = $this->samples[$index];
            $subset[1][] = $this->labels[$index];
        }

        return new self(...$subset);
    }

    /**
     * Group rows by label and return an array of stratified sets.
     *
     * @return array
     */
    public function stratify() : array
    {
        $strata = [];

        foreach ($this->labels as $index => $label) {
            $strata[$label][] = $this->samples[$index];
        }

        return $strata;
    }

    /**
     * Return an array with all the samples and labels.
     *
     * @return array
     */
    public function all() : array
    {
        return [$this->samples, $this->labels];
    }
}
