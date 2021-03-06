<?php
/**
 * @package    hubzero-cms
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Components\Search\Api\Controllers;

use Hubzero\Component\ApiController;
use Hubzero\Utility\Inflector;
use Hubzero\Utility\Str;
use Hubzero\Search\Query;
use Component;
use stdClass;
use Request;
use Event;
use User;

/**
 * API controller class for search
 */
class Searchv1_0 extends ApiController
{
	/**
	 * Display a list of entries
	 *
	 * @apiMethod GET
	 * @apiUri    /search/list
	 * @apiParameter {
	 * 		"name":          "type",
	 * 		"description":   "Content type (groups, members, etc.)",
	 * 		"type":          "string",
	 * 		"required":      false,
	 * 		"default":       ""
	 * }
	 * @apiParameter {
	 * 		"name":          "limit",
	 * 		"description":   "Number of result to return.",
	 * 		"type":          "integer",
	 * 		"required":      false,
	 * 		"default":       10
	 * }
	 * @apiParameter {
	 * 		"name":          "start",
	 * 		"description":   "Number of where to start returning results.",
	 * 		"type":          "integer",
	 * 		"required":      false,
	 * 		"default":       0
	 * }
	 * @apiParameter {
	 * 		"name":          "terms",
	 * 		"description":   "Terms to search for.",
	 * 		"type":          "string",
	 * 		"required":      true,
	 * 		"default":       "*:*"
	 * }
	 * @apiParameter {
	 * 		"name":          "sortBy",
	 * 		"description":   "Field to sort results by.",
	 * 		"type":          "string",
	 * 		"required":      false,
	 *      "default":       ""
	 * }
	 * @apiParameter {
	 * 		"name":          "sortDir",
	 * 		"description":   "Direction to sort results by.",
	 * 		"type":          "string",
	 * 		"required":      false,
	 * 		"default":       ""
	 * }
	 * @apiParameter {
	 * 		"name":          "filters",
	 * 		"description":   "Filters to apply to results.",
	 * 		"type":          "array",
	 * 		"required":      false,
	 * 		"default":       "[]"
	 * }
	 * @return  void
	 */
	public function listTask()
	{
		$config = Component::params('com_search');
		$query = new Query($config);

		$terms   = Request::getString('terms', '*:*');
		$limit   = Request::getInt('limit', 10);
		$start   = Request::getInt('start', 0);
		$sortBy  = Request::getString('sortBy', '');
		$sortDir = Request::getString('sortDir', '');
		$type    = Request::getString('type', '');
		$filters = Request::getArray('filters', array());

		// Apply the sorting
		if ($sortBy != '' && $sortDir != '')
		{
			$query = $query->sortBy($sortBy, $sortDir);
		}

		if ($type != '')
		{
			$query->addFilter('Type', array('hubtype', '=', $type));
		}

		// Administrators can see all records
		$isAdmin = User::authorise('core.admin');
		if ($isAdmin)
		{
			$query = $query->query($terms)->limit($limit)->start($start);
		}
		else
		{
			$query = $query->query($terms)->limit($limit)->start($start)->restrictAccess();
		}

		// Perform the query
		$query = $query->run();
		$results = $query->getResults();
		$numFound = $query->getNumFound();

		$highlightOptions = array(
			'format' =>'<span class="highlight">\1</span>',
			'html'   => false,
			'regex'  => "|%s|iu"
		);

		foreach ($results as &$result)
		{
			$snippet = '';
			foreach ($result as $field => &$r)
			{
				if (is_string($r))
				{
					$r = strip_tags($r);
				}

				if ($field != 'url')
				{
					$r = Str::highlight($r, $terms, $highlightOptions);
				}

				if ($field == 'description' || $field == 'fulltext' || $field == 'abstract')
				{
					if (isset($result['description']) && $result['description'] != $result['fulltext'])
					{
						$snippet .= $r;
					}
				}
			}

			$snippet = str_replace("\n", "", $snippet);
			$snippet = str_replace("\r", "", $snippet);
			$snippet  = Str::excerpt($snippet, $terms, $radius = 200, $ellipsis = '???');

			$result['snippet'] = $snippet;
		}

		$response = new stdClass;
		$response->results = $results;
		$response->total = $numFound;
		$response->showing = count($results);
		$response->success = true;

		$this->send($response);
	}

	/**
	 * Display a list of suggestions for a term
	 *
	 * @apiMethod GET
	 * @apiUri    /search/suggest
	 * @apiParameter {
	 * 		"name":          "terms",
	 * 		"description":   "Terms to get suggestions for.",
	 * 		"type":          "string",
	 * 		"required":      true,
	 * 		"default":       ""
	 * }
	 * @return  void
	 */
	public function suggestTask()
	{
		$terms = Request::getString('terms', '');
		$suggest = array();

		if ($terms != '')
		{
			$config = Component::params('com_search');
			$query = new \Hubzero\Search\Query($config);
			$suggest = $query->getSuggestions($terms);
		}

		$response = new stdClass;
		$response->results = $suggest;
		$response->success = true;

		$this->send($response);
	}

	/**
	 * Get suggestions for submitted terms
	 *
	 * @apiMethod GET
	 * @apiUri    /search/typeSuggestions
	 * @apiParameter {
	 * 		"name":          "terms",
	 * 		"description":   "Terms to search for.",
	 * 		"type":          "string",
	 * 		"required":      true,
	 * 		"default":       ""
	 * }
	 * @return  void
	 */
	public function typeSuggestionsTask()
	{
		$terms = Request::getString('terms', '');
		$suggestedWords = array();

		if ($terms != '')
		{
			$config = Component::params('com_search');
			$query = new Query($config);
			$typeSuggestions = $query->spellCheck($terms);

			if (!empty($typeSuggestions))
			{
				foreach ($typeSuggestions as $suggestion)
				{
					foreach ($suggestion->getWords() as $word)
					{
						array_push($suggestedWords, $word['word']);
					}
				}
			}
		}

		$response = new stdClass;
		$response->results = json_encode($suggestedWords);
		$response->success = true;

		$this->send($response);
	}

	/**
	 * Display a list of hub types for a term
	 *
	 * @apiMethod GET
	 * @apiUri    /search/getHubTypes
	 * @apiParameter {
	 * 		"name":          "terms",
	 * 		"description":   "Terms to search for.",
	 * 		"type":          "string",
	 * 		"required":      true,
	 * 		"default":       "*:*"
	 * }
	 * @return  void
	 */
	public function getHubTypesTask()
	{
		$config = Component::params('com_search');
		$query = new Query($config);

		$terms = Request::getString('terms', '*:*');
		$type  = Request::getString('type', '');
		$limit = 0;
		$start = 0;

		$types = Event::trigger('search.onGetTypes');
		foreach ($types as $type)
		{
			$query->addFacet($type, array('hubtype', '=', $type));
		}

		// Administrators can see all records
		$isAdmin = User::authorise('core.admin');
		if ($isAdmin)
		{
			$query = $query->query($terms)->limit($limit)->start($start);
		}
		else
		{
			$query = $query->query($terms)->limit($limit)->start($start)->restrictAccess();
		}

		$query = $query->run();
		$facets = array();
		$total = 0;
		foreach ($types as $type)
		{
			$name = $type;
			if (strpos($type, "-") !== false)
			{
				$name = substr($type, 0, strpos($type, "-"));
			}

			$count = $query->getFacetCount($type);
			$total += $count;


			$name = ucfirst(Inflector::pluralize($name));
			array_push($facets, array('type'=> $type, 'name' => $name,'count' => $count));
		}

		$response = new stdClass;
		$response->results = json_encode($facets);
		$response->total = $total;
		$response->success = true;

		$this->send($response);
	}
}
