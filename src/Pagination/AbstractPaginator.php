<?php

namespace As247\WpEloquent\Pagination;

use As247\WpEloquent\Contracts\View\Factory;
use Closure;
use As247\WpEloquent\Contracts\Support\Htmlable;
use As247\WpEloquent\Support\Arr;
use As247\WpEloquent\Support\Collection;
use As247\WpEloquent\Support\Str;
use As247\WpEloquent\Support\Traits\ForwardsCalls;
use JetBrains\PhpStorm\Pure;

/**
 * @mixin Collection
 */
abstract class AbstractPaginator implements Htmlable
{
    use ForwardsCalls;

    /**
     * All of the items being paginated.
     *
     * @var Collection
     */
    protected $items;

    /**
     * The number of items to be shown per page.
     *
     * @var int
     */
    protected $perPage;

    /**
     * The current page being "viewed".
     *
     * @var int
     */
    protected $currentPage;

    /**
     * The base path to assign to all URLs.
     *
     * @var string
     */
    protected $path = '/';

    /**
     * The query parameters to add to all URLs.
     *
     * @var array
     */
    protected $query = [];

    /**
     * The URL fragment to add to all URLs.
     *
     * @var string|null
     */
    protected $fragment;

    /**
     * The query string variable used to store the page.
     *
     * @var string
     */
    protected $pageName = 'page';

    /**
     * The number of links to display on each side of current page link.
     *
     * @var int
     */
    public $onEachSide = 3;

    /**
     * The paginator options.
     *
     * @var array
     */
    protected $options;

    /**
     * The current path resolver callback.
     *
     * @var Closure
     */
    protected static $currentPathResolver;

    /**
     * The current page resolver callback.
     *
     * @var Closure
     */
    protected static $currentPageResolver;

    /**
     * The query string resolver callback.
     *
     * @var Closure
     */
    protected static $queryStringResolver;

    /**
     * The view factory resolver callback.
     *
     * @var Closure
     */
    protected static $viewFactoryResolver;

    /**
     * The default pagination view.
     *
     * @var string
     */
    public static $defaultView = 'pagination::tailwind';

    /**
     * The default "simple" pagination view.
     *
     * @var string
     */
    public static string $defaultSimpleView = 'pagination::simple-tailwind';


    /**
     * Determine if the given value is a valid page number.
     *
     * @param int $page
     *
     * @return bool
     */
    protected function isValidPageNumber( $page ) : bool
    {
        return $page >= 1 && filter_var( $page, FILTER_VALIDATE_INT ) !== false;
    }


    /**
     * Get the URL for the previous page.
     *
     * @return string|null
     */
    public function previousPageUrl() : ?string
    {
        if( $this->currentPage() > 1 ) {
            return $this->url( $this->currentPage() - 1 );
        }

        return null;
    }


    /**
     * Create a range of pagination URLs.
     *
     * @param int $start
     * @param int $end
     *
     * @return array
     */
    public function getUrlRange( $start, $end ) : array
    {
        return asdb_collect( range( $start, $end ) )->mapWithKeys( function ( $page ) {
            return [ $page => $this->url( $page ) ];
        } )->all();
    }


    /**
     * Get the URL for a given page number.
     *
     * @param int $page
     *
     * @return string
     */
    public function url( $page )
    {
        if( $page <= 0 ) {
            $page = 1;
        }

        // If we have any extra query string key / value pairs that need to be added
        // onto the URL, we will put them in query string form and then attach it
        // to the URL. This allows for extra information like sortings storage.
        $parameters = [ $this->pageName => $page ];

        if( count( $this->query ) > 0 ) {
            $parameters = array_merge( $this->query, $parameters );
        }

        return $this->path()
            . ( Str::contains( $this->path(), '?' ) ? '&' : '?' )
            . Arr::query( $parameters )
            . $this->buildFragment();
    }


    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @param string|null $fragment
     *
     * @return $this|string|null
     */
    public function fragment( $fragment = null )
    {
        if( is_null( $fragment ) ) {
            return $this->fragment;
        }

        $this->fragment = $fragment;

        return $this;
    }


    /**
     * Add a set of query string values to the paginator.
     *
     * @param array|string|null $key
     * @param string|null       $value
     *
     * @return $this
     */
    public function appends( $key, $value = null ) : static
    {
        if( is_null( $key ) ) {
            return $this;
        }

        if( is_array( $key ) ) {
            return $this->appendArray( $key );
        }

        return $this->addQuery( $key, $value );
    }


    /**
     * Add an array of query string values.
     *
     * @param array $keys
     *
     * @return $this
     */
    protected function appendArray( array $keys ) : static
    {
        foreach( $keys as $key => $value ) {
            $this->addQuery( $key, $value );
        }

        return $this;
    }


    /**
     * Add all current query string values to the paginator.
     *
     * @return $this
     */
    public function withQueryString() : static
    {
        if( isset( static::$queryStringResolver ) ) {
            return $this->appends( call_user_func( static::$queryStringResolver ) );
        }

        return $this;
    }


    /**
     * Add a query string value to the paginator.
     *
     * @param string $key
     * @param string $value
     *
     * @return $this
     */
    protected function addQuery( string $key, string $value ) : self
    {
        if( $key !== $this->pageName ) {
            $this->query[ $key ] = $value;
        }

        return $this;
    }


    /**
     * Build the full fragment portion of a URL.
     *
     * @return string
     */
    protected function buildFragment() : string
    {
        return $this->fragment ? '#' . $this->fragment : '';
    }


    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param string $relation
     * @param array  $relations
     *
     * @return $this
     */
    public function loadMorph( $relation, $relations ) : self
    {
        $this->getCollection()->loadMorph( $relation, $relations );

        return $this;
    }


    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     *
     * @param string $relation
     * @param array  $relations
     *
     * @return $this
     */
    public function loadMorphCount( string $relation, array $relations ) : self
    {
        $this->getCollection()->loadMorphCount( $relation, $relations );

        return $this;
    }


    /**
     * Get the slice of items being paginated.
     *
     * @return array
     */
    #[Pure] public function items() : array
    {
        return $this->items->all();
    }


    /**
     * Get the number of the first item in the slice.
     *
     * @return int
     */
    public function firstItem() : float|int|null
    {
        return count( $this->items ) > 0 ? ( $this->currentPage - 1 ) * $this->perPage + 1 : null;
    }


    /**
     * Get the number of the last item in the slice.
     *
     * @return float|int|null
     */
    #[Pure] public function lastItem() : float|int|null
    {
        return count( $this->items ) > 0
            ? $this->firstItem() + $this->count() - 1
            : null;
    }


    /**
     * Transform each item in the slice of items using a callback.
     *
     * @param callable $callback
     *
     * @return $this
     */
    public function through( callable $callback ) : static
    {
        $this->items->transform( $callback );

        return $this;
    }


    /**
     * Get the number of items shown per page.
     *
     * @return int
     */
    public function perPage() : int
    {
        return $this->perPage;
    }


    /**
     * Determine if there are enough items to split into multiple pages.
     *
     * @return bool
     */
    public function hasPages() : bool
    {
        return $this->currentPage() !== 1 || $this->hasMorePages();
    }


    /**
     * Determine if the paginator is on the first page.
     *
     * @return bool
     */
    #[Pure] public function onFirstPage() : bool
    {
        return $this->currentPage() <= 1;
    }


    /**
     * Get the current page.
     *
     * @return int
     */
    public function currentPage() : int
    {
        return $this->currentPage;
    }


    /**
     * Get the query string variable used to store the page.
     *
     * @return string
     */
    public function getPageName() : string
    {
        return $this->pageName;
    }


    /**
     * Set the query string variable used to store the page.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setPageName( string $name ) : static
    {
        $this->pageName = $name;

        return $this;
    }


    /**
     * Set the base path to assign to all URLs.
     *
     * @param string $path
     *
     * @return $this
     */
    public function withPath( string $path ) : static
    {
        return $this->setPath( $path );
    }


    /**
     * Set the base path to assign to all URLs.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setPath( string $path ) : static
    {
        $this->path = $path;

        return $this;
    }


    /**
     * Set the number of links to display on each side of current page link.
     *
     * @param int $count
     *
     * @return $this
     */
    public function onEachSide( int $count ) : static
    {
        $this->onEachSide = $count;

        return $this;
    }


    /**
     * Get the base path for paginator generated URLs.
     *
     * @return string|null
     */
    public function path() : ?string
    {
        return $this->path;
    }


    /**
     * Resolve the current request path or return the default value.
     *
     * @param string $default
     *
     * @return string
     */
    public static function resolveCurrentPath( string $default = '/' ) : string
    {
        if( isset( static::$currentPathResolver ) ) {
            return call_user_func( static::$currentPathResolver );
        }

        return $default;
    }


    /**
     * Set the current request path resolver callback.
     *
     * @param Closure $resolver
     *
     * @return void
     */
    public static function currentPathResolver( Closure $resolver ) : void
    {
        static::$currentPathResolver = $resolver;
    }


    /**
     * Resolve the current page or return the default value.
     *
     * @param string $pageName
     * @param int    $default
     *
     * @return int
     */
    public static function resolveCurrentPage( $pageName = 'page', $default = 1 ) : int
    {
        if( isset( static::$currentPageResolver ) ) {
            return call_user_func( static::$currentPageResolver, $pageName );
        }

        return $default;
    }


    /**
     * Set the current page resolver callback.
     *
     * @param Closure $resolver
     *
     * @return void
     */
    public static function currentPageResolver( Closure $resolver ) : void
    {
        static::$currentPageResolver = $resolver;
    }


    /**
     * Set with query string resolver callback.
     *
     * @param Closure $resolver
     *
     * @return void
     */
    public static function queryStringResolver( Closure $resolver ) : void
    {
        static::$queryStringResolver = $resolver;
    }


    /**
     * Get an instance of the view factory from the resolver.
     *
     * @return Factory
     */
    public static function viewFactory() : Factory
    {
        return call_user_func( static::$viewFactoryResolver );
    }


    /**
     * Set the view factory resolver callback.
     *
     * @param Closure $resolver
     *
     * @return void
     */
    public static function viewFactoryResolver( Closure $resolver ) : void
    {
        static::$viewFactoryResolver = $resolver;
    }


    /**
     * Set the default pagination view.
     *
     * @param string $view
     *
     * @return void
     */
    public static function defaultView( $view ) : void
    {
        static::$defaultView = $view;
    }


    /**
     * Set the default "simple" pagination view.
     *
     * @param string $view
     *
     * @return void
     */
    public static function defaultSimpleView( $view ) : void
    {
        static::$defaultSimpleView = $view;
    }


    /**
     * Indicate that Tailwind styling should be used for generated links.
     *
     * @return void
     */
    public static function useTailwind() : void
    {
        static::defaultView( 'pagination::tailwind' );
        static::defaultSimpleView( 'pagination::simple-tailwind' );
    }


    /**
     * Indicate that Bootstrap 4 styling should be used for generated links.
     *
     * @return void
     */
    public static function useBootstrap() : void
    {
        static::defaultView( 'pagination::bootstrap-4' );
        static::defaultSimpleView( 'pagination::simple-bootstrap-4' );
    }


    /**
     * Indicate that Bootstrap 3 styling should be used for generated links.
     *
     * @return void
     */
    public static function useBootstrapThree() : void
    {
        static::defaultView( 'pagination::default' );
        static::defaultSimpleView( 'pagination::simple-default' );
    }


    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator
     */
    public function getIterator() : \ArrayIterator
    {
        return $this->items->getIterator();
    }


    /**
     * Determine if the list of items is empty.
     *
     * @return bool
     */
    #[Pure] public function isEmpty() : bool
    {
        return $this->items->isEmpty();
    }


    /**
     * Determine if the list of items is not empty.
     *
     * @return bool
     */
    public function isNotEmpty() : bool
    {
        return $this->items->isNotEmpty();
    }


    /**
     * Get the number of items for the current page.
     *
     * @return int
     */
    #[Pure] public function count() : int
    {
        return $this->items->count();
    }


    /**
     * Get the paginator's underlying collection.
     *
     * @return Collection
     */
    public function getCollection() : Collection
    {
        return $this->items;
    }


    /**
     * Set the paginator's underlying collection.
     *
     * @param Collection $collection
     *
     * @return $this
     */
    public function setCollection( Collection $collection ) : static
    {
        $this->items = $collection;

        return $this;
    }


    /**
     * Get the paginator options.
     *
     * @return array
     */
    public function getOptions() : array
    {
        return $this->options;
    }


    /**
     * Determine if the given item exists.
     *
     * @param mixed $key
     *
     * @return bool
     */
    #[Pure] public function offsetExists( mixed $key ) : bool
    {
        return $this->items->has( $key );
    }


    /**
     * Get the item at the given offset.
     *
     * @param mixed $key
     *
     * @return mixed
     */
    public function offsetGet( mixed $key ) : mixed
    {
        return $this->items->get( $key );
    }


    /**
     * Set the item at the given offset.
     *
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    public function offsetSet( mixed $key, mixed $value ) : void
    {
        $this->items->put( $key, $value );
    }


    /**
     * Unset the item at the given key.
     *
     * @param mixed $key
     *
     * @return void
     */
    public function offsetUnset( mixed $key ) : void
    {
        $this->items->forget( $key );
    }


    /**
     * Render the contents of the paginator to HTML.
     *
     * @return string
     */
    public function toHtml() : string
    {
        return (string) $this->render();
    }


    /**
     * Make dynamic calls into the collection.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public function __call( string $method, array $parameters )
    {
        return $this->forwardCallTo( $this->getCollection(), $method, $parameters );
    }


    /**
     * Render the contents of the paginator when casting to string.
     *
     * @return string
     */
    public function __toString() : string
    {
        return (string) $this->render();
    }
}
