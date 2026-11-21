<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\ExtensionInterface;

final class CodeBlockExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $renderer = new CodeBlockRenderer;

        $environment->addRenderer(FencedCode::class, $renderer, 10);
        $environment->addRenderer(IndentedCode::class, $renderer, 10);
    }
}
