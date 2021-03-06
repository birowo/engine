<?php
namespace Minds\Core\Boost;

use Minds\Interfaces\BoostHandlerInterface;
use Minds\Core;
use Minds\Core\Data;
use Minds\Entities;
use Minds\Helpers;

/**
 * Newsfeed Boost handler
 * @todo Proper DI
 */
class Network implements BoostHandlerInterface
{
    protected $handler = 'newsfeed';
    protected $mongo;
    protected $db;


    public function __construct($options = [], Data\Interfaces\ClientInterface $mongo = null, Data\Call $db = null)
    {
        $this->mongo = $mongo ?: Data\Client::build('MongoDB');
        $this->db = $db ?: new Data\Call('entities_by_time');
    }

    /**
     * Boost an entity
     * @param  object/int $entity - the entity to boost
     * @param  int        $impressions
     * @return boolean
     */
    public function boost($boost, $impressions = 0)
    {

        $documentId = $this->mongo->insert("boost", [
          'guid' => $boost->getGuid(),
          'owner_guid' => $boost->getOwner()->guid,
          'impressions' => $boost->getBid(),
          'state' => 'review',
          'type' => $this->handler
        ]);

        if ($documentId) {
            return (string) $documentId;
        }

        return $boost->getGuid();
    }

     /**
     * Return boosts for review
     * @param  int    $limit
     * @param  string $offset
     * @return array
     */
    public function getReviewQueue($limit, $offset = "")
    {
        $query = [ 'state'=>'review', 'type'=> $this->handler ];
        if ($offset) {
            $query['_id'] = [ '$gt' => $offset ];
        }
        $queue = $this->mongo->find("boost", $query, [
            'limit' => $limit,
            'sort' => [ '_id' => 1 ],
        ]);
        if (!$queue) {
            return false;
        }

        $guids = [];
        $end = "";
        foreach ($queue as $data) {
            $_id = (string) $data['_id'];
            $guids[$_id] = (string) $data['guid'];
            $end = $data['guid'];
            //$this->mongo->remove("boost", ['_id' => $_id]);
        }

        if (!$guids) {
            return false;
        }
        
        $prepared = new Core\Data\Cassandra\Prepared\Custom();
        $collection = \Cassandra\Type::collection(\Cassandra\Type::text())
            ->create(... array_values($guids));
        $prepared->query("SELECT * from entities_by_time WHERE key= ? AND column1 IN ? LIMIT ?",
          [ "boost:$this->handler", $collection, count($guids) ]);

        $cql = Core\Di\Di::_()->get('Database\Cassandra\Cql');
        $data = $cql->request($prepared);

        $boosts = [];
        foreach ($data as $row) {
            $boost = (new Entities\Boost\Network())
              ->loadFromArray(json_decode($row['value'], true));
            //double check
            if (isset($guids[$boost->getId()])) {
                $boosts[] = $boost;
            }
        }
        return $boosts;
    }

    /**
     * Return the review count
     * @return int
     */
    public function getReviewQueueCount()
    {
        $query = ['state' => 'review', 'type' => $this->handler];
        $count = $this->mongo->count("boost", $query);
        return $count;
    }

    /**
     * Get our own submitted Boosts
     * @param  int    $limit
     * @param  string $offset
     * @return array
     */
    public function getOutbox($limit, $offset = "")
    {
        $db = new Data\Call('entities_by_time');
        $data = $db->getRow("boost:$this->handler:requested:" . Core\Session::getLoggedinUser()->guid, [
          'limit'=>$limit,
          'offset'=>$offset,
          'reversed'=>true
        ]);

        $boosts = [];
        foreach ($data as $guid => $raw_data) {
            //$raw_data['guid']
            $boosts[] = (new Entities\Boost\Network())
              ->loadFromArray(json_decode($raw_data, true));
        }
        return $boosts;
    }

    /**
     * Gets a single boost entity
     * @param  mixed $guid
     * @return object
     */
    public function getBoostEntity($guid)
    {
        $db = new Data\Call('entities_by_time');
        $data = $db->getRow("boost:$this->handler", ['limit'=>1, 'offset'=>$guid]);
        if (key($data) != $guid) {
            return false;
        }

        $boost = (new Entities\Boost\Network($db))
          ->loadFromArray(json_decode($data[$guid], true));
        return $boost;
    }

    /**
     * Accept a boost
     * @param  mixed $_id
     * @param  int   $impressions Optional. Defaults to 0.
     * @return boolean
     */
    public function accept($boost, $impressions = 0)
    {
        $accept = $this->mongo->update("boost", ['_id' => $boost->getId()], ['state'=>'approved', 'rating'=>$boost->getRating()]);
        $boost->setState('approved');
        $boost->setRating($rating);
        if ($accept) {
            //remove from review
            //$db->removeAttributes("boost:newsfeed:review", array($guid));
            //clear the counter for boost_impressions
            //Helpers\Counters::clear($guid, "boost_impressions");

            Core\Events\Dispatcher::trigger('notification', 'boost', [
                'to'=> [ $boost->getOwner()->guid ],
                'entity' => $boost->getEntity(),
                'from'=> 100000000000000519,
                'title' => $boost->getEntity()->title,
                'notification_view' => 'boost_accepted',
                'params' => ['impressions' => $boost->getBid()],
                'impressions' => $boost->getBid()
              ]);
            $boost->save();
        }
        return $accept;
    }

    /**
     * Reject a boost
     * @param  mixed $_id
     * @return boolean
     */
    public function reject($boost)
    {
        $this->mongo->remove("boost", ['_id'=>$boost->getId()]);
        $boost->setState('rejected')
          ->save();

        Core\Events\Dispatcher::trigger('notification', 'boost', [
            'to'=> [ $boost->getOwner()->guid ],
            'from'=> 100000000000000519,
            'entity' => $boost->getEntity(),
            'title' => $boost->getEntity()->title,
            'notification_view' => 'boost_rejected',
        ]);
        return true;//need to double check somehow..
    }

    /**
     * Return a boost
     * @return array
     */
    public function getBoost($offset = "")
    {
        $boosts = $this->getBoosts(1);
        return $boosts[0];
    }

    /**
     * Expire a boost from the queue
     * @param  object $boost
     * @return null
     */
    private function expireBoost($boost)
    {
        if (!$boost) {
            return;
        }

        $boost->setState('completed')
          ->save();

        $this->mongo->remove("boost", [ '_id' => $boost->getId() ]);

        Core\Events\Dispatcher::trigger('notification', 'boost', [
          'to'=> [ $boost->getOwner()->guid ],
          'from'=> 100000000000000519,
          'entity' => $boost->getEntity(),
          'title' => $boost->getEntity()->title,
          'notification_view' => 'boost_completed',
          'params' => ['impressions' => $boost->getBid()],
          'impressions' => $boost->getBid()
        ]);
    }

    /**
     * Polyfills boost thumbs
     * @param  string[] $boosts
     * @return string[]
     */
    private function patchThumbs($boosts)
    {
        $keys = [];
        foreach ($boosts as $boost) {
            $keys[] = "thumbs:up:entity:$boost->guid";
        }
        $thumbs = $this->db->getRows($keys, ['offset'=> Core\Session::getLoggedInUserGuid()]);
        foreach ($boosts as $k => $boost) {
            $key = "thumbs:up:entity:$boost->guid";
            if (isset($thumbs[$key])) {
                $boosts[$k]->{'thumbs:up:user_guids'} = array_keys($thumbs[$key]);
            }
        }
        return $boosts;
    }

    private function filterBlocked($boosts)
    {
        //owner_guids
        $owner_guids = [];
        foreach ($boosts as $boost) {
            $owner_guids[] = $boost->owner_guid;
        }
        $blocked = array_flip(Core\Security\ACL\Block::_()->isBlocked($owner_guids, Core\Session::getLoggedInUserGuid()));

        foreach ($boosts as $i => $boost) {
            if (isset($blocked[$boost->owner_guid])) {
                unset($boosts[$i]);
            }
        }

        return $boosts;
    }

    /**
     * Gets all boosts
     * @param  integer $limit
     * @param  boolean $increment
     * @return array
     */
    public function getBoosts($limit = 2, $increment = true, $rating = 0)
    {
        $cacher = Core\Data\cache\factory::build('apcu');
        $mem_log =  $cacher->get(Core\Session::getLoggedinUser()->guid . ":seenboosts:$this->handler") ?: [];

        $opts = [
            'type'=>$this->handler,
            'state'=>'approved',
            //'rating'=>[
            //    '$exists' => true, 
            //    '$lte' => $rating != 0 ? $rating : 2 
            // ],
        ];
        if ($mem_log) {
            $opts['_id'] =  [ '$gt' => end($mem_log) ];
        }

        $boosts = $this->mongo->find("boost", $opts, [
            'limit' => 50,
            'sort' => [ '_id' => 1 ],
        ]);
        if (!$boosts) {
            return null;
        }

        $return = [];
        foreach ($boosts as $data) {
            if (count($return) >= $limit) {
                break;
            }
            if (in_array((string) $data['_id'], $mem_log)) {
                continue; // already seen
            }

            $impressions = $data['impressions'];
            if ($increment) {
                //increment impression counter
                Helpers\Counters::increment((string) $data['_id'], "boost_impressions", 1);
                //get the current impressions count for this boost
                Helpers\Counters::increment(0, "boost_impressions", 1);
            }
            $count = Helpers\Counters::get((string) $data['_id'], "boost_impressions", false);

            $boost = $this->getBoostEntity($data['guid']);
            $legacy_boost = false;

            if ($count > $impressions) {
                if ($legacy_boost) {
                    $this->mongo->remove("boost", ['_id' => $data['_id']]);
                /*    Core\Events\Dispatcher::trigger('notification', 'boost', [
                      'to'=>array($entity->owner_guid),
                      'from'=> 100000000000000519,
                      'entity' => $entity,
                      'title' => $entity->title,
                      'notification_view' => 'boost_completed',
                      'params' => array('impressions'=>$boost['impressions']),
                      'impressions' => $boost['impressions']
                      ]);*/
                } else {
                    $this->expireBoost($boost);
                }

                continue; //max count met
            }
            array_push($mem_log, (string) $data['_id']);
            $cacher->set(Core\Session::getLoggedinUser()->guid . ":seenboosts:$this->handler", $mem_log, (60 * 60) / 2); //cache for 1/2 an hour

            if ($legacy_boost) {
                $return[] = $entity;
            } else {
                $return[$data['guid']] = $boost->getEntity();
            }
        }
        if (empty($return) && !empty($mem_log)) {
            $cacher->destroy(Core\Session::getLoggedinUser()->guid . ":seenboosts:$this->handler");
            $this->tries++;
            if ($this->tries > 2) {
                return $this->getBoosts($limit, $increment);
            }
        }
        $return = $this->patchThumbs($return);
        $return = $this->filterBlocked($return);
        return $return;
    }
}
