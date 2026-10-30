<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Guide;

use Flarum\Http\{RequestUtil, UrlGenerator};
use Illuminate\Contracts\Container\Container;
use Laminas\Diactoros\Response\HtmlResponse;
use Maxcuso\Roleplay\Presentation\InterfaceIcons;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

/** Самостоятельный документ для iframe: штатные стили, без приложения и оболочки форума. */
final class UpdateController implements RequestHandlerInterface
{
    public function __construct(private UrlGenerator $url, private Container $container, private InterfaceIcons $icons) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('viewForum');
        $html = GuideDocument::read($this->url, 'update/content.html');
        $html = preg_replace_callback('~data-icon="([a-zA-Z0-9_.-]+)"~',
            fn ($match) => 'class="Icon '.htmlspecialchars($this->icons->get($match[1]), ENT_QUOTES).'"', $html);
        // Берём существующую сборку: общие компоненты, Font Awesome и шрифты не дублируются.
        $assets = $this->container->make('flarum.assets.forum');
        $cssUrl = $assets->makeCss()->getUrl();
        // При CDN/Kit основная сборка содержит пустой шрифт до загрузки внешних стилей.
        // В iframe используем штатные локальные Free-шрифты, как fallback самого Flarum.
        $iconFonts = '';
        foreach (['fa-regular-400' => 400, 'fa-solid-900' => 900] as $file => $weight) {
            $fontUrl = $this->container->make('filesystem')->disk('flarum-assets')->url('fonts/'.$file.'.woff2');
            $revision = $assets->fontRevision($file.'.woff2');
            if ($revision !== null) $fontUrl .= (str_contains($fontUrl, '?') ? '&' : '?').'v='.$revision;
            $iconFonts .= '@font-face{font-family:"Font Awesome 7 Free";font-style:normal;font-weight:'.$weight
                .';font-display:block;src:url('.json_encode($fontUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR).') format("woff2")}';
        }
        $theme = $request->getQueryParams()['theme'] ?? 'auto';
        if (!is_string($theme) || !in_array($theme, ['auto', 'light', 'dark'], true)) $theme = 'auto';
        $document = strtr(file_get_contents(__DIR__.'/../../resources/update/document.html'), [
            '{{stylesheet}}' => htmlspecialchars($cssUrl ?? '', ENT_QUOTES),
            '{{iconFonts}}' => $iconFonts,
            '{{theme}}' => $theme,
            '{{content}}' => $html,
            '{{script}}' => file_get_contents(__DIR__.'/../../resources/guide/typography.js')
                ."\n".file_get_contents(__DIR__.'/../../resources/update/embed.js'),
        ]);
        return new HtmlResponse($document, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }
}
