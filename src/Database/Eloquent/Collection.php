<?php

namespace As247\WpEloquent\Database\Eloquent;

use As247\WpEloquent\Contracts\Queue\QueueableCollection;
use As247\WpEloquent\Contracts\Queue\QueueableEntity;
use As247\WpEloquent\Contracts\Support\Arrayable;
use As247\WpEloquent\Support\Arr;
use As247\WpEloquent\Support\Collection as BaseCollection;
use As247\WpEloquent\Support\Str;
use Closure;
use LogicException;

class Collection extends BaseCollection implements QueueableCollection
{
    /**
     * Find a model in the collection by key.
     *
     * @param mixed $key
     * @param mixed $default
     *
     * @return \As247\WpEloquent\Database\Eloquent\Model|static|null
     */
    public function find( $key, $default = null )
    {
        if( $key instanceof Model ) {
            $key = $key->getKey();
        }

        if( $key instanceof Arrayable ) {
            $key = $key->toArray();
        }

        if( is_array( $key ) ) {
            if( $this->isEmpty() ) {
                return new static;
            }

            return $this->whereIn( $this->first()->getKeyName(), $key );
        }

        return Arr::first( $this->items, function ( $model ) use ( $key ) {
            return $model->getKey() == $key;
        }, $default );
    }


    /**
     * Load a set of relationships onto the collection.
     *
     * @param array|string $relations
     *
     * @return $this
     */
    public function load( $relations )
    {
        if( $this->isNotEmpty() ) {
            if( is_string( $relations ) ) {
                $relations = func_get_args();
            }

            $query = $this->first()->newQueryWithoutRelationships()->with( $relations );

            $this->items = $query->eagerLoadRelations( $this->items );
        }

        return $this;
    }


    /**
     * Load a set of relationship counts onto the collection.
     *
     * @param array|string $relations
     *
     * @return $this
     */
    public function loadCount( $relations )
    {
        if( $this->isEmpty() ) {
            return $this;
        }

        $models = $this->first()->newModelQuery()
            ->whereKey( $this->modelKeys() )
            ->select( $this->first()->getKeyName() )
            ->withCount( ...func_get_args() )
            ->get()
            ->keyBy( $this->first()->getKeyName() );

        $attributes = Arr::except(
            array_keys( $models->first()->getAttributes() ),
            $models->first()->getKeyName()
        );

        $this->each( function ( $model ) use ( $models, $attributes ) {
            $extraAttributes = Arr::only( $models->get( $model->getKey() )->getAttributes(), $attributes );

            $model->forceFill( $extraAttributes )->syncOriginalAttributes( $attributes );
        } );

        return $this;
    }


    /**
     * Load a set of relationships onto the collection if they are not already eager loaded.
     *
     * @param array|string $relations
     *
     * @return $this
     */
    public function loadMissing( $relations )
    {
        if( is_string( $relations ) ) {
            $relations = func_get_args();
        }

        foreach( $relations as $key => $value ) {
            if( is_numeric( $key ) ) {
                $key = $value;
            }

            $segments = explode( '.', explode( ':', $key )[0] );

            if( Str::contains( $key, ':' ) ) {
                $segments[ count( $segments ) - 1 ] .= ':' . explode( ':', $key )[1];
            }

            $path = [];

            foreach( $segments as $segment ) {
                $path[] = [ $segment => $segment ];
            }

            if( is_callable( $value ) ) {
                $path[ count( $segments ) - 1 ][ end( $segments ) ] = $value;
            }

            $this->loadMissingRelation( $this, $path );
        }

        return $this;
    }


    /**
     * Load a relationship path if it is not already eager loaded.
     *
     * @param Collection $models
     * @param array      $path
     *
     * @return void
     */
    protected function loadMissingRelation( self $models, array $path ) : void
    {
        $relation = array_shift( $path );

        $name = explode( ':', key( $relation ) )[0];

        if( is_string( reset( $relation ) ) ) {
            $relation = reset( $relation );
        }

        $models->filter( function ( $model ) use ( $name ) {
            return ! is_null( $model ) && ! $model->relationLoaded( $name );
        } )->load( $relation );

        if( empty( $path ) ) {
            return;
        }

        $models = $models->pluck( $name );

        if( $models->first() instanceof BaseCollection ) {
            $models = $models->collapse();
        }

        $this->loadMissingRelation( new static( $models ), $path );
    }


    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param string $relation
     * @param array  $relations
     *
     * @return $this
     */
    public function loadMorph( $relation, $relations )
    {
        $this->pluck( $relation )
            ->filter()
            ->groupBy( function ( $model ) {
                return get_class( $model );
            } )
            ->each( function ( $models, $className ) use ( $relations ) {
                static::make( $models )->load( $relations[ $className ] ?? [] );
            } );

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
    public function loadMorphCount( $relation, $relations )
    {
        $this->pluck( $relation )
            ->filter()
            ->groupBy( function ( $model ) {
                return get_class( $model );
            } )
            ->each( function ( $models, $className ) use ( $relations ) {
                static::make( $models )->loadCount( $relations[ $className ] ?? [] );
            } );

        return $this;
    }


    /**
     * Determine if a key exists in the collection.
     *
     * @param mixed $key
     * @param mixed $operator
     * @param mixed $value
     *
     * @return bool
     */
    public function contains( $key, $operator = null, $value = null ) : bool
    {
        if( func_num_args() > 1 || $this->useAsCallable( $key ) ) {
            return parent::contains( ...func_get_args() );
        }

        if( $key instanceof Model ) {
            return parent::contains( static function ( $model ) use ( $key ) {
                return $model->is( $key );
            } );
        }

        return parent::contains( static function ( $model ) use ( $key ) {
            return $model->getKey() === $key;
        } );
    }


    /**
     * Get the array of primary keys.
     *
     * @return array
     */
    public function modelKeys() : array
    {
        return array_map( static function ( $model ) {
            return $model->getKey();
        }, $this->items );
    }


    /**
     * Merge the collection with the given items.
     *
     * @param \ArrayAccess|array $items
     *
     * @return static
     */
    public function merge( $items ) : static
    {
        $dictionary = $this->getDictionary();

        foreach( $items as $item ) {
            $dictionary[ $item->getKey() ] = $item;
        }

        return new static( array_values( $dictionary ) );
    }


    /**
     * Run a map over each of the items.
     *
     * @param callable $callback
     *
     * @return Collection
     */
    public function map( callable $callback )
    {
        $result = parent::map( $callback );

        return $result->contains( function ( $item ) {
            return ! $item instanceof Model;
        } ) ? $result->toBase() : $result;
    }


    /**
     * Reload a fresh model instance from the database for all the entities.
     *
     * @param array|string $with
     *
     * @return static
     */
    public function fresh( $with = [] )
    {
        if( $this->isEmpty() ) {
            return new static;
        }

        $model = $this->first();

        $freshModels = $model->newQueryWithoutScopes()
            ->with( is_string( $with ) ? func_get_args() : $with )
            ->whereIn( $model->getKeyName(), $this->modelKeys() )
            ->get()
            ->getDictionary();

        return $this->map( function ( $model ) use ( $freshModels ) {
            return $model->exists && isset( $freshModels[ $model->getKey() ] )
                ? $freshModels[ $model->getKey() ] : null;
        } );
    }


    /**
     * Diff the collection with the given items.
     *
     * @param \ArrayAccess|array $items
     *
     * @return static
     */
    public function diff( $items ) : static
    {
        $diff = new static;

        $dictionary = $this->getDictionary( $items );

        foreach( $this->items as $item ) {
            if( ! isset( $dictionary[ $item->getKey() ] ) ) {
                $diff->add( $item );
            }
        }

        return $diff;
    }


    /**
     * Intersect the collection with the given items.
     *
     * @param \ArrayAccess|array $items
     *
     * @return static
     */
    public function intersect( $items ) : static
    {
        $intersect = new static;

        if( empty( $items ) ) {
            return $intersect;
        }

        $dictionary = $this->getDictionary( $items );

        foreach( $this->items as $item ) {
            if( isset( $dictionary[ $item->getKey() ] ) ) {
                $intersect->add( $item );
            }
        }

        return $intersect;
    }


    /**
     * Return only unique items from the collection.
     *
     * @param string|callable|null $key
     * @param bool                 $strict
     *
     * @return static
     */
    public function unique( $key = null, $strict = false ) : static
    {
        if( ! is_null( $key ) ) {
            return parent::unique( $key, $strict );
        }

        return new static( array_values( $this->getDictionary() ) );
    }


    /**
     * Returns only the models from the collection with the specified keys.
     *
     * @param mixed $keys
     *
     * @return static
     */
    public function only( $keys ) : static
    {
        if( is_null( $keys ) ) {
            return new static( $this->items );
        }

        $dictionary = Arr::only( $this->getDictionary(), $keys );

        return new static( array_values( $dictionary ) );
    }


    /**
     * Returns all models in the collection except the models with specified keys.
     *
     * @param mixed $keys
     *
     * @return static
     */
    public function except( $keys ) : static
    {
        $dictionary = Arr::except( $this->getDictionary(), $keys );

        return new static( array_values( $dictionary ) );
    }


    /**
     * Make the given, typically visible, attributes hidden across the entire collection.
     *
     * @param array|string $attributes
     *
     * @return $this
     */
    public function makeHidden( $attributes ) : static
    {
        return $this->each->makeHidden( $attributes );
    }


    /**
     * Make the given, typically hidden, attributes visible across the entire collection.
     *
     * @param array|string $attributes
     *
     * @return $this
     */
    public function makeVisible( $attributes ) : static
    {
        return $this->each->makeVisible( $attributes );
    }


    /**
     * Append an attribute across the entire collection.
     *
     * @param array|string $attributes
     *
     * @return $this
     */
    public function append( $attributes ) : static
    {
        return $this->each->append( $attributes );
    }


    /**
     * Get a dictionary keyed by primary keys.
     *
     * @param \ArrayAccess|array|null $items
     *
     * @return array
     */
    public function getDictionary( $items = null ) : array
    {
        $items = is_null( $items ) ? $this->items : $items;

        $dictionary = [];

        foreach( $items as $value ) {
            $dictionary[ $value->getKey() ] = $value;
        }

        return $dictionary;
    }

    /**
     * The following methods are intercepted to always return base collections.
     */

    /**
     * Get an array with the values of a given key.
     *
     * @param string|array $value
     * @param null         $key
     *
     * @return Collection
     */
    public function pluck( $value, $key = null ) : static
    {
        return $this->toBase()->pluck( $value, $key );
    }


    /**
     * Get the keys of the collection items.
     *
     * @return static
     */
    public function keys() : static
    {
        return $this->toBase()->keys();
    }


    /**
     * Zip the collection together with one or more arrays.
     *
     * @param mixed ...$items
     *
     * @return BaseCollection
     */
    public function zip( $items ) : static
    {
        return call_user_func_array( [ $this->toBase(), 'zip' ], func_get_args() );
    }


    /**
     * Collapse the collection of items into a single array.
     *
     * @return BaseCollection
     */
    public function collapse() : static
    {
        return $this->toBase()->collapse();
    }


    /**
     * Get a flattened array of the items in the collection.
     *
     * @param int $depth
     *
     * @return BaseCollection
     */
    public function flatten( $depth = INF ) : static
    {
        return $this->toBase()->flatten( $depth );
    }


    /**
     * Flip the items in the collection.
     *
     * @return BaseCollection
     */
    public function flip() : static
    {
        return $this->toBase()->flip();
    }


    /**
     * Pad collection to the specified length with a value.
     *
     * @param int   $size
     * @param mixed $value
     *
     * @return BaseCollection
     */
    public function pad( $size, $value ) : static
    {
        return $this->toBase()->pad( $size, $value );
    }


    /**
     * Get the comparison function to detect duplicates.
     *
     * @param bool $strict
     *
     * @return Closure
     */
    protected function duplicateComparator( bool $strict ) : Closure
    {
        return static function ( $a, $b ) {
            return $a->is( $b );
        };
    }


    /**
     * Get the type of the entities being queued.
     *
     * @return string|null
     *
     * @throws LogicException
     */
    public function getQueueableClass() : ?string
    {
        if( $this->isEmpty() ) {
            return null;
        }

        $class = get_class( $this->first() );

        $this->each( function ( $model ) use ( $class ) {
            if( get_class( $model ) !== $class ) {
                throw new LogicException( 'Queueing collections with multiple model types is not supported.' );
            }
        } );

        return $class;
    }


    /**
     * Get the identifiers for all of the entities.
     *
     * @return array
     */
    public function getQueueableIds() : array
    {
        if( $this->isEmpty() ) {
            return [];
        }

        return $this->first() instanceof QueueableEntity
            ? $this->map->getQueueableId()->all()
            : $this->modelKeys();
    }


    /**
     * Get the relationships of the entities being queued.
     *
     * @return array
     */
    public function getQueueableRelations() : array
    {
        if( $this->isEmpty() ) {
            return [];
        }

        $relations = $this->map->getQueueableRelations()->all();

        if( count( $relations ) === 0 || $relations === [ [] ] ) {
            return [];
        }

        return count( $relations ) === 1
            ? reset( $relations )
            : array_intersect( ...$relations );
    }


    /**
     * Get the connection of the entities being queued.
     *
     * @return string|null
     *
     * @throws LogicException
     */
    public function getQueueableConnection() : ?string
    {
        if( $this->isEmpty() ) {
            return null;
        }

        $connection = $this->first()->getConnectionName();

        $this->each( function ( $model ) use ( $connection ) {
            if( $model->getConnectionName() !== $connection ) {
                throw new LogicException( 'Queueing collections with multiple model connections is not supported.' );
            }
        } );

        return $connection;
    }


    /**
     * Get the Eloquent query builder from the collection.
     *
     * @return Builder
     *
     * @throws LogicException
     */
    public function toQuery() : Builder
    {
        $model = $this->first();

        if( ! $model ) {
            throw new LogicException( 'Unable to create query for empty collection.' );
        }

        $class = get_class( $model );

        if( $this->filter( function ( $model ) use ( $class ) {
            return ! $model instanceof $class;
        } )->isNotEmpty() ) {
            throw new LogicException( 'Unable to create query for collection with mixed types.' );
        }

        return $model->newModelQuery()->whereKey( $this->modelKeys() );
    }
}
