<?php
/**
 * @package    hubzero-cms
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Components\Search\Helpers;

use Components\Tags\Models\FocusArea;
use \Hubzero\Search\Query;
use \Hubzero\Search\Index;
use stdClass;
use Solarium;

/**
 * Solr helper class
 */
class SolrHelper
{
	public function __construct()
	{
		$config = Component::params('com_search');
		$this->query = new \Hubzero\Search\Query($config);
		$this->index = new \Hubzero\Search\Index($config);

		return $this;
	}

	/**
	 * Solr search
	 * 
	 * @param	$search	string	Search string
	 * @param  	$sortBy	string	Sort by
	 * @param  	$limit	int		Limit number of records
	 * @param  	$start	int		Start at record
	 * @param 	$fl		array	Filters (as focus area ids)
	 * @param 	$facets	array	Facets (FocusArea::fromObject)
	 * @static
	 * @access  public
	 * @return  mixed	 */
	public function search($search = '', $sortBy = 'score', $limit = 0, $start = 0, $filters = array(), $facets = array())
	{
		$limit = (!$limit ? Config::get('list_limit') : $limit);

		// Ontology filters
		$fl = isset($filters['fl']) ? $filters['fl'] : array();
		unset($filters['fl']);
		$leaves = array();
		$selected = array();
		foreach ($fl as $leaf_child) {
			$path = array();
			if ($fa = FocusArea::oneOrFail($leaf_child)) {
				$child = $fa;
				$ctag = $child->tag->tag;
				$parent = $child->parents()->row();
				$leaf = '"' . $parent->tag->tag . '.' . $child->tag->tag . '"';
				while (!is_null($parent->get('id'))) {
					$ptag = $parent->tag->tag;
					$path[] = $ptag . '.' . $child->tag->tag;

					$child = $parent; $ctag = $ptag;
					$parent = $parent->parents()->row();
				}

				$leaves[$ctag][] = $leaf;
				if (array_key_exists($ctag, $selected)) {
					$selected[$ctag] = array_unique(array_merge($selected[$ctag], $path));
				} else {
					$selected[$ctag] = $path;
				}
			}
		}

		// Add sorting
		$this->query->query($search ? $search : '*:*')->limit($limit)->start($start);
		$this->query->sortBy($sortBy, 'desc');
		// Always add desc by publish_up at end
		$this->query->sortBy('publish_up', 'desc');

		// Add filters
		$this->query->addFilter('hubtype', 'hubtype:publication'); // Only publications
		$this->query->addFilter('access_level', 'access_level:public'); // Only published
		foreach ($filters as $filter => $value) {
			if (!is_array($value)) {
				$this->query->addFilter($filter, $filter . ':"' . $value . '"');
			} else {
				$this->query->addFilter($filter, $filter . ':(' . implode(' OR ', $value) . ')');
			}
		}
		foreach ($leaves as $tag => $filter) {
			if ($filter) {
				$this->query->addFilter($tag, 'tags:(' . implode(' OR ', $filter) . ')', $tag);
			}
		}

		// Focus areas as facets
		foreach($facets as $fa) {
			$fa_key = $fa->tag->tag;
			$multifacet = $this->query->adapter->getFacetMultiQuery($fa_key);
			// $multifacet->createQuery($fa_key, 'tags:' . $fa_key . '*')->setExcludes(array($fa_key));
			$multifacet->createQuery($fa_key, 'tags:/' . $fa_key . '.*/')->setExcludes(array($fa_key));
			$tags = $fa->render('search');
			array_walk($tags, function($tag) use ($multifacet, $fa_key) {
				$multifacet->createQuery($tag, 'tags:"' . $tag . '"')->setExcludes(array($fa_key));
			});
		}

		// Do the solr search
		try
		{
			$this->query = $this->query->run();
		}
		catch (\Solarium\Exception\HttpException $e)
		{
			$this->query->query('')->limit($limit)->start($start)->run();
			\Notify::warning(Lang::txt('COM_SEARCH_MALFORMED_QUERY'));
		}

		// Return results
		return array(
			'results' => $this->query->getResults(),
			'numFound' => $this->query->getNumFound(),
			'leaves' => $leaves, // For view
			'filters' => $selected, // For debugging purposes
			'facets' => $this->query->resultsFacetSet
		);
	}

	/**
	 * parseDocumentID - returns a friendly way to access the type and id from a solr ID 
	 * 
	 * @param   string  $id 
	 * @static
	 * @access  public
	 * @return  mixed
	 */
	public function parseDocumentID($id = '')
	{
		if ($id != '')
		{
			$parts = explode('-', $id);

			if (count($parts) == 3)
			{
				$type = $parts[0] . '-' . $parts[1];
				$id   = $parts[2];
			}
			elseif (count($parts) == 2)
			{
				$type = $parts[0];
				$id   = $parts[1];
			}

			return array('type' => $type, 'id' => $id);
		}
		return false;
	}

	/**
	 * removeDocument - Removes a single document from the search index
	 *
	 * @param string $id
	 * @access public
	 * @return boolean 
	 */
	public function removeDocument($id)
	{
		if ($id != null)
		{
			return $this->index->delete(array('id' => $id));
		}
		else
		{
			return false;
		}
	}
}
