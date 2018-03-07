<?php

/**
 *    Copyright 2015-2017 ppy Pty. Ltd.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Libraries;

use App\Libraries\Elasticsearch\BoolQuery;
use App\Libraries\Elasticsearch\Highlight;
use App\Libraries\Elasticsearch\QueryHelper;
use App\Libraries\Elasticsearch\Search;
use App\Libraries\Elasticsearch\SearchResponse;
use App\Models\Forum\Forum;
use App\Models\Forum\Post;
use App\Models\User;

// FIXME: remove ArrayAccess after refactored
class PostSearch extends Search implements \ArrayAccess
{
    const HIGHLIGHT_FRAGMENT_SIZE = 50;

    protected $forumId;
    protected $topicId;
    protected $includeSubforums;
    protected $queryString;
    protected $userId;

    public function __construct(array $options = [])
    {
        parent::__construct(Post::esIndexName(), $options);

        $this->userId = get_int($options['userId'] ?? -1);
        $this->queryString = presence($options['query'] ?? '');

        $this->includeSubforums = get_bool($options['includeSubforums'] ?? false);
        $this->forumId = get_int($options['forumId'] ?? null);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray() : array
    {
        $query = (new BoolQuery())
            ->must(['term' => ['poster_id' => $this->userId]])
            ->filter(['term' => ['type' => 'posts']]);

        if (isset($this->queryString)) {
            $query->must(QueryHelper::queryString($this->queryString, ['search_content']));
            $this->highlight(
                (new Highlight)
                    ->field('search_content')
                    ->fragmentSize(static::HIGHLIGHT_FRAGMENT_SIZE)
                    ->numberOfFragments(3)
            );
        }

        if (isset($this->forumId)) {
            $forumIds = $this->includeSubforums
                ? Forum::findOrFail($this->forumId)->allSubForums()
                : [$this->forumId];

            $query->filter(['terms' => ['forum_id' => $forumIds]]);
        }

        $this->query($query);

        // default sort
        $this->sort(['post_time' => 'desc']);

        return parent::toArray();
    }

    public function data()
    {
        return $this->response();
    }

    public function response() : SearchResponse
    {
        return parent::response()->recordType(Post::class)->idField('post_id');
    }

    public function total()
    {
        return min($this->response()->total(), static::MAX_RESULTS);
    }

    public function params()
    {
        return $this->getPaginationParams();
    }

    //================
    // ArrayAccess
    //================

    public function offsetExists($key)
    {
        return in_array($key, ['data', 'total', 'params'], true);
    }

    public function offsetGet($key)
    {
        if ($this->offsetExists($key) === false) {
            return;
        }

        // reroute to method
        return (new \ReflectionObject($this))->getMethod(camel_case($key))->invoke($this);
    }

    public function offsetSet($key, $value)
    {
        throw new \BadMethodCallException('not supported');
    }

    public function offsetUnset($key)
    {
        throw new \BadMethodCallException('not supported');
    }
}
