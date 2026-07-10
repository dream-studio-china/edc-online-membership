<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseService;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Promotion\Service\Dsl\DslSyntaxException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PromotionTemplateService extends BaseService implements PromotionTemplateServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, PromotionTemplate::class);
    }

    public function parseDsl(string $dsl): array
    {
        try {
            $lexer = new Lexer();
            $tokens = $lexer->tokenize($dsl);

            $parser = new Parser();
            $ast = $parser->parse($tokens);

            return [
                'ast' => json_decode(json_encode($ast), true),
                'errors' => [],
            ];
        } catch (DslSyntaxException $e) {
            return [
                'ast' => null,
                'errors' => [[
                    'line' => $e->line,
                    'col' => $e->col,
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }

    public function simulate(PromotionTemplate $template, array $sampleContext): array
    {
        return [
            'template_id' => $template->getId(),
            'type' => $template->getType(),
            'dsl' => $template->getDsl(),
            'sampleContext' => $sampleContext,
            'matched' => false,
            'actions' => [],
        ];
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        if (is_array($data) && isset($data['dsl']) && is_string($data['dsl'])) {
            $result = $this->parseDsl($data['dsl']);
            if (!empty($result['errors'])) {
                throw $result['errors'][0]; // Let caller handle
            }
            $data['astCache'] = $result['ast'];
        }

        return parent::update($object, $data, $noFlush);
    }
}
