<?php

namespace Rubix\Engine\Estimators;

use MathPHP\Statistics\Average;
use Rubix\Engine\Datasets\Dataset;
use Rubix\Engine\Datasets\Supervised;
use Rubix\Engine\Estimators\Persistable;
use Rubix\Engine\Estimators\Predictions\Probabalistic;
use InvalidArgumentException;

class RandomForest implements Classifier, Persistable
{
    /**
     * The number of trees to train in the ensemble.
     *
     * @var int
     */
    protected $trees;

    /**
     * The ratio of training samples to train each decision tree on.
     *
     * @var float
     */
    protected $ratio;

    /**
     * The minimum number of samples that each node must contain in order to
     * form a consensus to make a prediction.
     *
     * @var int
     */
    protected $minSamples;

    /**
     * The maximum depth of a branch before the tree is terminated.
     *
     * @var int
     */
    protected $maxDepth;

    /**
     * The decision trees that make up the forest.
     *
     * @var array
     */
    protected $forest = [
        //
    ];

    /**
     * @param  int  $trees
     * @param  float  $ratio
     * @param  int  $minSamples
     * @param  int  $maxDepth
     * @throws \InvalidArgumentException
     * @return void
     */
    public function __construct(int $trees = 50, float $ratio = 0.1, int $minSamples = 5,
                                int $maxDepth = 10)
    {
        if ($trees < 1) {
            throw new InvalidArgumentException('The number of trees cannot be'
                . ' less than 1.');
        }

        if ($ratio < 0.01 || $ratio > 1.0) {
            throw new InvalidArgumentException('Sample ratio must be a float'
                . ' value between 0.01 and 1.0.');
        }

        $this->trees = $trees;
        $this->ratio = $ratio;
        $this->minSamples = $minSamples;
        $this->maxDepth = $maxDepth;
    }

    /**
     * The number of trees in the forest.
     *
     * @return int
     */
    public function trees() : int
    {
        return count($this->forest);
    }

    /**
     * Train a Random Forest by training an ensemble of decision trees on random
     * subsets of the training data.
     *
     * @param  \Rubix\Engine\Datasets\Supervised  $dataset
     * @return void
     */
    public function train(Supervised $dataset) : void
    {
        $this->forest = [];

        for ($i = 0; $i < $this->trees; $i++) {
            $tree = new DecisionTree($this->minSamples, $this->maxDepth);

            $subset = $dataset->randomSubsetWithReplacement($this->ratio);

            $tree->train($subset);

            $this->forest[] = $tree;
        }
    }

    /**
     * Make a prediction on a given sample.
     *
     * @param  \Rubix\Engine\Datasets\Dataset  $samples
     * @return array
     */
    public function predict(Dataset $samples) : array
    {
        $predictions = $results = [];

        foreach ($this->forest as $tree) {
            foreach ($tree->predict($samples) as $i => $prediction) {
                $results[$i][] = $prediction->outcome();
            }
        }

        foreach ($results as $outcomes) {
            $counts = array_count_values($outcomes);

            $outcome = array_search(max($counts), $counts);

            $probability = $counts[$outcome] / count($outcomes);

            $predictions[] = new Probabalistic($outcome, $probability);
        }

        return $predictions;
    }
}
