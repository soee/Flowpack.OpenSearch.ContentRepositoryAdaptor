<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\ErrorHandling;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\RuntimeException;

/**
 * Handle error result and build human readable output for analysis
 */
class FileStorage implements ErrorStorageInterface
{
    public function __construct()
    {
        if (!file_exists(FLOW_PATH_DATA . 'Logs/OpenSearch')) {
            mkdir(FLOW_PATH_DATA . 'Logs/OpenSearch');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function logErrorResult(array $errorResult): string
    {
        $referenceCode = date('YmdHis', $_SERVER['REQUEST_TIME']) . substr(md5((string)rand()), 0, 6);
        $filename = FLOW_PATH_DATA . 'Logs/OpenSearch/' . $referenceCode . '.txt';
        $message = sprintf('OpenSearch API Error detected - See also: Data/Logs/OpenSearch/%s on host: %s', basename($filename), gethostname());

        if (file_exists(FLOW_PATH_DATA . 'Logs/OpenSearch') && is_dir(FLOW_PATH_DATA . 'Logs/OpenSearch') && is_writable(FLOW_PATH_DATA . 'Logs/OpenSearch')) {
            file_put_contents($filename, $this->renderErrorResult($errorResult));
        } else {
            throw new RuntimeException('OpenSearch error response could not be written to ' . $filename, 1588835331);
        }

        return $message;
    }

    protected function renderErrorResult(array $errorResult): string
    {
        $error = json_encode($errorResult, JSON_PRETTY_PRINT);

        return sprintf("Error:\n=======\n\n%s\n\n", $error);
    }
}
