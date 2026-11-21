<?php

namespace App\Support\Markdown;

use InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

final class CodeBlockRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        if (! $node instanceof FencedCode && ! $node instanceof IndentedCode) {
            throw new InvalidArgumentException('Unsupported code block node.');
        }

        $language = $node instanceof FencedCode
            ? ($node->getInfoWords()[0] ?? '')
            : '';

        $languageLabel = $language !== '' ? $language : 'كود';
        $codeAttributes = $language !== '' ? ['class' => 'language-'.$language] : [];

        $header = new HtmlElement(
            'div',
            ['class' => 'ai-code-header'],
            new HtmlElement('span', ['class' => 'ai-code-language'], Xml::escape($languageLabel))
            .'<button type="button" class="ai-code-copy" data-copy-code aria-label="نسخ الكود">'
            .'<svg class="ai-code-copy-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>'
            .'<span data-copy-label>نسخ</span>'
            .'</button>',
        );

        $code = new HtmlElement('code', $codeAttributes, Xml::escape($node->getLiteral()));
        $pre = new HtmlElement('pre', [], $code);

        return new HtmlElement(
            'div',
            ['class' => 'ai-code-block', 'data-code-block' => 'true', 'dir' => 'ltr'],
            (string) $header.(string) $pre,
        );
    }
}
