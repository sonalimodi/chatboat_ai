<?php

namespace App\Providers;

use App\Services\Rag\Embeddings\EmbeddingProviderInterface;
use App\Services\Rag\Embeddings\GeminiEmbeddingProvider;
use App\Services\Rag\Retrieval\HybridRetriever;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(EmbeddingProviderInterface::class, function () {
            return new GeminiEmbeddingProvider(config('rag.embeddings'));
        });

        // One per request/CLI run, so the chunk corpus is loaded once.
        $this->app->singleton(HybridRetriever::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
