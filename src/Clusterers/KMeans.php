<?php

namespace Rubix\ML\Clusterers;

use Rubix\ML\Learner;
use Rubix\ML\Verbose;
use Rubix\Tensor\Matrix;
use Rubix\ML\Persistable;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Other\Helpers\Params;
use Rubix\ML\Other\Helpers\DataType;
use Rubix\ML\Other\Traits\LoggerAware;
use Rubix\ML\Kernels\Distance\Distance;
use Rubix\ML\Kernels\Distance\Euclidean;
use Rubix\ML\Other\Specifications\DatasetIsCompatibleWithEstimator;
use InvalidArgumentException;
use RuntimeException;

/**
 * K Means
 *
 * A fast centroid-based hard clustering algorithm capable of clustering
 * linearly separable data points given a number of target clusters set by the
 * parameter K. K Means is trained with mini batch gradient descent using the
 * within cluster distance as a loss function.
 *
 * References:
 * [1] D. Arthur et al. (2006). k-means++: The Advantages of Careful Seeding.
 * [2] D. Sculley. (2010). Web-Scale K-Means Clustering.
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
class KMeans implements Learner, Persistable, Verbose
{
    use LoggerAware;

    /**
     * The target number of clusters.
     *
     * @var int
     */
    protected $k;

    /**
     * The size of each mini batch in samples.
     *
     * @var int
     */
    protected $batchSize;

    /**
     * The distance function to use when computing the distances.
     *
     * @var \Rubix\ML\Kernels\Distance\Distance
     */
    protected $kernel;

    /**
     * The minimum change in the size of each cluster for training
     * to continue.
     *
     * @var int
     */
    protected $minChange;

    /**
     * The maximum number of iterations to run until the algorithm
     * terminates.
     *
     * @var int
     */
    protected $epochs;

    /**
     * The computed centroid vectors of the training data.
     *
     * @var array[]
     */
    protected $centroids = [
        //
    ];

    /**
     * @param int $k
     * @param int $batchSize
     * @param \Rubix\ML\Kernels\Distance\Distance|null $kernel
     * @param int $minChange
     * @param int $epochs
     * @throws \InvalidArgumentException
     */
    public function __construct(
        int $k,
        int $batchSize = 100,
        ?Distance $kernel = null,
        int $minChange = 1,
        int $epochs = 300
    ) {
        if ($k < 1) {
            throw new InvalidArgumentException('Must target at least'
                . " 1 cluster, $k given.");
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be greater'
                . " than 0, $batchSize given.");
        }

        if ($minChange < 1) {
            throw new InvalidArgumentException('Min change cannot be less'
                . " than 1, $minChange given.");
        }

        if ($epochs < 1) {
            throw new InvalidArgumentException('Estimator must train'
                . " for at least 1 epoch, $epochs given.");
        }

        $this->k = $k;
        $this->batchSize = $batchSize;
        $this->kernel = $kernel ?? new Euclidean();
        $this->minChange = $minChange;
        $this->epochs = $epochs;
    }

    /**
     * Return the integer encoded estimator type.
     *
     * @return int
     */
    public function type() : int
    {
        return self::CLUSTERER;
    }

    /**
     * Return the data types that this estimator is compatible with.
     *
     * @return int[]
     */
    public function compatibility() : array
    {
        return [
            DataType::CONTINUOUS,
        ];
    }

    /**
     * Has the learner been trained?
     *
     * @return bool
     */
    public function trained() : bool
    {
        return !empty($this->centroids);
    }

    /**
     * Return the computed cluster centroids of the training data.
     *
     * @return array
     */
    public function centroids() : array
    {
        return $this->centroids;
    }

    /**
     * Train the learner with a dataset.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     */
    public function train(Dataset $dataset) : void
    {
        DatasetIsCompatibleWithEstimator::check($dataset, $this);

        if ($this->logger) {
            $this->logger->info('Learner initialized w/ '
                . Params::stringify([
                    'k' => $this->k,
                    'kernel' => $this->kernel,
                    'epochs' => $this->epochs,
                ]));
        }

        $this->centroids = $this->initialize($dataset);

        $samples = $dataset->samples();
        $labels = array_fill(0, $dataset->numRows(), null);

        $sizes = array_fill(0, $this->k, 0);

        $order = range(0, $dataset->numRows() - 1);

        for ($epoch = 1; $epoch <= $this->epochs; $epoch++) {
            shuffle($order);

            array_multisort($order, $samples, $labels);

            $sChunks = array_chunk($samples, $this->batchSize);
            $lChunks = array_chunk($labels, $this->batchSize);

            $changed = 0;

            foreach ($sChunks as $i => $batch) {
                $lHat = $lChunks[$i];

                foreach ($batch as $j => $sample) {
                    $label = $this->assign($sample);

                    $expected = $lHat[$j];

                    if ($label !== $expected) {
                        $labels[$i] = $label;

                        $sizes[$label]++;

                        if (isset($expected)) {
                            $sizes[$expected]--;
                        }

                        $changed++;
                    }
                }

                foreach ($this->centroids as $label => &$centroid) {
                    $step = Matrix::quick($batch)->transpose()->mean();

                    $rate = 1. / ($sizes[$label] ?: self::EPSILON);

                    foreach ($centroid as $column => &$mean) {
                        $mean = (1. - $rate) * $mean + $rate * $step[$column];
                    }
                }
            }

            if ($this->logger) {
                $this->logger->info("Epoch $epoch complete,"
                    . " changed=$changed");
            }

            if ($changed < $this->minChange) {
                break 1;
            }
        }

        if ($this->logger) {
            $this->logger->info('Training complete');
        }
    }

    /**
     * Cluster the dataset by assigning a label to each sample.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return array
     */
    public function predict(Dataset $dataset) : array
    {
        if (!$this->centroids) {
            throw new RuntimeException('Estimator has not been trained.');
        }

        DatasetIsCompatibleWithEstimator::check($dataset, $this);

        return array_map([self::class, 'assign'], $dataset->samples());
    }

    /**
     * Label a given sample based on its distance from a particular centroid.
     *
     * @param array $sample
     * @throws \RuntimeException
     * @return int
     */
    protected function assign(array $sample) : int
    {
        $bestDistance = INF;
        $bestCluster = -1;

        foreach ($this->centroids as $cluster => $centroid) {
            $distance = $this->kernel->compute($sample, $centroid);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestCluster = $cluster;
            }
        }

        return (int) $bestCluster;
    }

    /**
     * Initialize the cluster centroids using the k-means++ method.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \RuntimeException
     * @return array
     */
    protected function initialize(Dataset $dataset) : array
    {
        $n = $dataset->numRows();

        if ($n < $this->k) {
            throw new RuntimeException('The number of samples cannot be less'
                . ' than the number of target clusters.');
        }

        $weights = array_fill(0, $n, 1. / $n);

        $centroids = [];

        for ($i = 0; $i < $this->k; $i++) {
            $subset = $dataset->randomWeightedSubsetWithReplacement(1, $weights);

            $centroids[] = $subset[0];

            if ($i === $this->k) {
                break 1;
            }

            foreach ($dataset as $j => $sample) {
                $closest = INF;

                foreach ($centroids as $centroid) {
                    $distance = $this->kernel->compute($sample, $centroid);

                    if ($distance < $closest) {
                        $closest = $distance;
                    }
                }

                $weights[$j] = $closest ** 2;
            }

            $total = array_sum($weights) ?: self::EPSILON;

            foreach ($weights as &$weight) {
                $weight /= $total;
            }
        }

        return $centroids;
    }
}
