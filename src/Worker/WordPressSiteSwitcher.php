<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Exceptions\SiteUnavailableException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;
use Fuzeo\Queue\Runtime\NativeWordPressRuntime;
use Fuzeo\Queue\Runtime\WordPressRuntime;

final class WordPressSiteSwitcher implements SiteSwitcher
{
    public function __construct(
        private readonly WordPressRuntime $wp = new NativeWordPressRuntime(),
        private readonly SitePolicy $policy = new SitePolicy(),
    ) {
    }

    public function run(ExecutionContext $context, callable $callback): mixed
    {
        $depth = $this->wp->switchedStackDepth();
        $originBlog = $this->wp->currentBlogId();
        if ($context->scope === ExecutionScope::Site) {
            $this->policy->assertExecutable($this->wp, $context);
            $this->wp->switchToBlog($context->siteId);
            if (function_exists('switch_to_blog') && $this->wp->currentBlogId() !== $context->siteId) {
                throw new SiteUnavailableException(
                    'Site ' . $context->siteId . ' could not be switched into. The job will not run against another blog.'
                );
            }
        }
        try {
            return $callback();
        } finally {
            $this->unwind($depth, $originBlog);
        }
    }

    private function unwind(int $depth, int $originBlog): void
    {
        $guard = 32;
        while ($guard-- > 0 && $this->wp->switchedStackDepth() > $depth) {
            $this->wp->restorePreviousBlog();
        }
        if ($this->wp->currentBlogId() !== $originBlog) {
            $this->wp->restoreBlog();
            if ($this->wp->currentBlogId() !== $originBlog) {
                $this->wp->switchToBlog($originBlog);
            }
        }
    }
}
