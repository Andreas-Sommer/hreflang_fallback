<?php

namespace Belsignum\HreflangFallback\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Frontend\Event\ModifyHrefLangTagsEvent;

class HreflangFallbackEventListener
{
    protected int $sys_language_uid;
    protected ConnectionPool $connectionPool;
    protected UriBuilder $uriBuilder;
    protected ServerRequestInterface $request;

    public function __construct(
        ConnectionPool $connectionPool,
        UriBuilder     $uriBuilder
    )
    {
        $this->connectionPool = $connectionPool;
        $this->uriBuilder = $uriBuilder;
    }

    public function __invoke(ModifyHrefLangTagsEvent $event): void
    {
        $hrefLangs = $event->getHrefLangs();
        $this->request = $event->getRequest();
        $this->sys_language_uid = $this->request->getAttribute('language')->getLanguageId();

        /** @var PageArguments $routing */
        $routing = $this->request->getAttribute('routing');

        if($routing->getPageType() > 0)
        {
            // todo: do we need to remove hreflang meta tags here?
            return;
        }

        foreach ($this->request->getAttribute('site')->getLanguages() as $_ => $language)
        {
            $hrefLang = $language->getHreflang();

            if ($this->comparisonToInsert($hrefLangs, $language, $routing))
            {
                $event->addHrefLang(
                    $hrefLang,
                    $this->generateUri($language, $routing)
                );
            }
        }
    }

    private function comparisonToInsert(array $hrefLangs, SiteLanguage $language, PageArguments $routing): bool
    {
        // check if already set or configuration handling is false
        if (isset($hrefLangs[$language->getHreflang()]) || $this->configurationHandling($language) === false)
        {
            return false;
        }

        // validate page language config is not limited
        switch ($this->getPageLanguageConfig($routing))
        {
            case 0:
                // no language limitation
                return true;
            case 1:
                // hide default language -> check if current lang is not $language
                return $language->getLanguageId() !== 0;
            case 2:
                // hide if no translation exists -> so no fallback is possible
                return $language->getLanguageId() === 0;
            case 3:
                // combination of 1 and 2 -> nothing to add
            default:
                return false;
        }
    }

    private function getPageLanguageConfig(PageArguments $routing): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $result = $queryBuilder
            ->select('l18n_cfg')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($routing->getPageId(), Connection::PARAM_INT))
            )
            ->execute()
            ->fetchAssociative();

        return $result['l18n_cfg'];
    }

    private function generateUri(SiteLanguage $language, PageArguments $routing): string
    {
        $uri = $this->uriBuilder
            ->reset()
            ->setTargetPageUid($routing->getPageId())
            ->setCreateAbsoluteUri(true)
            ->setLanguage($language->getLanguageId());


        $queryParams = $this->request->getQueryParams();
        if (empty($queryParams) === false)
        {
            $configParams = $this->extractParamsByConfig($queryParams);
            if (
                $configParams !== null
                && isset($configParams['params']['action'])
                && isset($configParams['params']['controller'])
            )
            {
                $data = $configParams['params'];
                unset($data['action']);
                unset($data['controller']);
                $uri->uriFor(
                    $configParams['params']['action'],
                    $data,
                    $configParams['params']['controller'],
                    $configParams['queryPrefixParts']['extension'],
                    $configParams['queryPrefixParts']['plugin']
                );
            }
        }
        return $uri->build();
    }

    private function extractParamsByConfig(array $queryParams): ?array
    {
        $siteConfig = $this->request->getAttribute('site')->getConfiguration();
        $routeEnhancers = $this->cleanRoutEnhancers($siteConfig['routeEnhancers']);
        foreach ($queryParams as $queryPrefixName => $params)
        {
            $queryPrefixParts = $this->disassembleQueryPrefixParts($queryPrefixName);
            if ($queryPrefixParts === null || is_array($params) === false)
            {
                continue;
            }

            if ($this->compareParamsWithRouteEnhancers($params, $queryPrefixParts, $routeEnhancers))
            {
                return [
                    'queryPrefixParts' => $queryPrefixParts,
                    'params'           => $params
                ];
            }
        }
        return null;
    }

    private function cleanRoutEnhancers(array $routeEnhancers): array
    {
        return array_filter($routeEnhancers, function ($config)
        {
            return $config['type'] === 'Extbase';
        });
    }

    private function disassembleQueryPrefixParts(string $queryPrefixName): ?array
    {
        if (str_starts_with($queryPrefixName, 'tx_') === false)
        {
            return null;
        }

        $partialStr = str_replace('tx_', '', $queryPrefixName);
        $partials = GeneralUtility::trimExplode('_', $partialStr, true);
        return [
            'extension' => $partials[0],
            'plugin'    => $partials[1]
        ];
    }

    private function compareParamsWithRouteEnhancers(array $params, array $queryPrefixParts, array $routeEnhancers): bool
    {
        foreach ($routeEnhancers as $name => $config)
        {
            if (
                strcasecmp($config['extension'], $queryPrefixParts['extension']) === 0
                && strcasecmp($config['plugin'], $queryPrefixParts['plugin']) === 0
            )
            {
                return true;
            }
        }
        return false;
    }

    private function configurationHandling(SiteLanguage $language): bool
    {
        if($language->getLanguageId() === 0 || $language->enabled() === false)
        {
            return false;
        }

        $configuration = $language->toArray();
        return $language->getFallbackType() === 'fallback' || (bool)($configuration['syntheticHreflangTag'] ?? false) === true;

        #
    }
}
