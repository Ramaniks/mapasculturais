<?php
namespace MapasCulturais\Controllers;

use MapasCulturais\App;

/**
 * Event Controller
 *
 * By default this controller is registered with the id 'event'.
 *
 */
class Event extends EntityController {
    use \MapasCulturais\Traits\ControllerUploads,
        \MapasCulturais\Traits\ControllerTypes,
        \MapasCulturais\Traits\ControllerMetaLists,
        \MapasCulturais\Traits\ControllerAgentRelation,
        \MapasCulturais\Traits\ControllerVerifiable,
        \MapasCulturais\Traits\ControllerSoftDelete;

    /**
     * Creates a new Event
     *
     * This action requires authentication and outputs the json with the new event or with an array of errors.
     *
     * <code>
     * // creates the url to this action
     * $url = $app->createUrl('agent');
     * </code>
     */
    function POST_index(){
        $app = App::i();

        $app->hook('entity(event).insert:before', function() use($app){
            $this->owner = $app->user->profile;
        });
        parent::POST_index();
    }


    function GET_create() {
        if(key_exists('projectId', $this->urlData) && is_numeric($this->urlData['projectId'])){
            $project = $this->repository->find($this->urlData['projectId']);
            if($project)
                App::i()->hook('entity(event).new', function() use ($project){
                    $this->project = $project;
                });
        }
        parent::GET_create();
    }

    function API_findBySpace(){
        if(!key_exists('spaceId', $this->data)){
            $this->errorJson('spaceId is required');
            return;
        }

        $query_data = $this->getData;

        $spaceId = $query_data['spaceId'];
        $date_from  = key_exists('@from',   $query_data) ? $query_data['@from'] : date("Y-m-d");
        $date_to    = key_exists('@to',     $query_data) ? $query_data['@to']   : $date_from;

        unset(
            $query_data['@from'],
            $query_data['@to'],
            $query_data['spaceId']
        );

        $app = App::i();
        $space = $app->repo('Space')->find($spaceId);

        if(!$space){
            $this->errorJson('space not found');
            return;
        }

        $occurrences = array();
        $occurrences_readable = array();

        $events = $app->repo('Event')->findBySpace($space, $date_from, $date_to);

        $event_ids = array_map(function($e) {
            return $e->id;

        }, $events);

        foreach($events as $e){
            $occurrences[$e->id] = $e->findOccurrencesBySpace($space, $date_from, $date_to);
            $occurrences_readable[$e->id] = array();

            $occurrences_readable[$e->id] = array_map(function($occ){
                if(isset($occ->rule->description)) {
                    return $occ->rule->description;
                }
            }, $occurrences[$e->id]);

        }

        if($event_ids){
            $event_data = array('id' => 'IN(' . implode(',', $event_ids) .')');

            foreach($query_data as $key => $val)
                if($key[0] === '@' || $key == '_geoLocation')
                    $event_data[$key] = $val;

            $result = $this->apiQuery($event_data);

            if(is_array($result)){
                foreach($result as $k => $e){
                    //@TODO: verify if occurrences and readable occurrences were selected in query data
                    $result[$k]['occurrences'] = key_exists($e['id'], $occurrences) ? $occurrences[$e['id']] : array();
                    $result[$k]['readableOccurrences'] = key_exists($e['id'], $occurrences_readable) ? $occurrences_readable[$e['id']] : array();
                }
            }

            $this->apiResponse($result);
        }else{
            $this->apiResponse(key_exists('@count', $query_data) ? 0 : array());
        }
    }

    function apiQueryByLocation($query_data){
        $app = App::i();

        $date_from  = key_exists('@from',   $query_data) ? $query_data['@from'] : date("Y-m-d");
        $date_to    = key_exists('@to',     $query_data) ? $query_data['@to']   : $date_from;

        unset(
            $query_data['@from'],
            $query_data['@to']
        );

        if(key_exists('_geoLocation', $query_data)){
            $space_controller = App::i()->controller('space');

            $space_data = array(
                '@select' => 'id',
                '_geoLocation' => $this->data['_geoLocation']
            );

            $space_ids = array_map(
                function($e){
                    return $e['id'];

                },
                $space_controller->apiQuery($space_data)
            );
            $events = $this->repository->findBySpace($space_ids, $date_from, $date_to);

            unset($query_data['_geoLocation']);
        }else{
            $events = $this->repository->findByDateInterval($date_from, $date_to);
        }

        $event_ids = array_map(function($e) {
            return $e->id;
        }, $events);

        $result_occurrences = array();

        foreach($events as $evt){
            $e = array();

            $e['spaces'] = array();
            $e['occurrences'] = array();
            $e['occurrencesReadable'] = array();

//            $occurrences = $evt->findOccurrences($date_from, $date_to);
//
//            foreach($occurrences as $occ){
//                $space_id = $occ->spaceId;
//
//                if(!key_exists($space_id, $e['spaces']))
//                    $e['spaces'][$space_id] = $app->repo('Space')->find($space_id);
//
//
//                if(!key_exists($space_id, $e['occurrences']))
//                    $e['occurrences'][$space_id] = array();
//
//                if(!key_exists($space_id, $e['occurrencesReadable']))
//                    $e['occurrencesReadable'][$space_id] = array();
//
//                $e['occurrences'][$space_id][] = $occ;
//
//                $month = $app->txt($occ->startsOn->format('F'));
//                $str = $occ->startsOn->format('d \d\e') . ' ' . $month . ' às ' . $occ->startsAt->format('H:i');
//
//                if(!in_array($str, $e['occurrencesReadable'][$space_id]))
//                    $e['occurrencesReadable'][$space_id][] = $str;
//            }

            $result_occurrences[$evt->id] = $e;
        }

        if($event_ids){
            $query_data['id'] = 'IN(' . implode(',', $event_ids) .')';
            // @TODO: verificar se o @select tem o id
            $result = $this->apiQuery($query_data);

            if(is_array($result)){
                foreach($result as $k => $r){
                    $result[$k] = array_merge($result_occurrences[$r['id']], $r);
                }
            }
        }else{
            $result = key_exists('@count', $query_data) ? 0 : array();
        }

        return $result;
    }

    function API_findByLocation(){

        $this->apiResponse($this->apiQueryByLocation($this->getData));

    }
}
