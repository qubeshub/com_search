<?php
/**
 * @package    hubzero-cms
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Components\Search\Models\Solr\Filters;

use Hubzero\Database\Relational;
use Hubzero\Config\Registry;

/**
 * Database model for filters of the type list
 *
 * @uses  \Hubzero\Database\Relational
 */
class Listfilter extends Filter
{
	/**
	 * Render form fields on the filter list
	 *
	 * @param   array   $counts  counts retrieved from solr search
	 * @param   array   $selectedOptions  list of options currently selected
	 * @return  string
	 */
	public function renderHtml($counts, $selectedOptions)
	{
		$html = '<ul>';
		$html .= '<li><fieldset class="search-filters"><legend>' . $this->label . '</legend>';
		$countTotal = 0;
		if ($this->options->count() > 0)
		{
			$html .= '<ul>';
			foreach ($this->options()->order('ordering', 'ASC') as $option)
			{
				$countIndex = $this->field . '_' . $option->id;
				$count = isset($counts[$countIndex]) ? $counts[$countIndex] : 0;
				$countTotal += $count;
				if (!$count)
				{
					continue;
				}
				$checked = in_array($option->value, $selectedOptions) ? 'checked' : '';
				$html .= '<li><label><input type="checkbox" class="checkbox" name="filters[' .
					$this->field . '][' . $option->id . ']" value="' . $option->value . '"' . $checked . '/>';
				$html .= $option->value . '<span class="item-count">' . $count . '</span></a>';
				$html .= '</label></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</fieldset></li></ul>';
		return ($countTotal) ? $html : '';
	}

	/**
	 * Add filter options to solr count query
	 *
	 * @param   object   $multifacet  Solarium object that permits getting individual facet counts
	 * @return  string
	 */
	public function addCounts(\Solarium\Component\Facet\MultiQuery $multifacet)
	{
		$filterField = $this->get('field');
		foreach ($this->options as $option)
		{
			$queryName = $filterField . '_' . $option->get('id');
			$optionQuery = $filterField . ':"' . $option->get('value') . '"';
			$multifacet->createQuery($queryName, $optionQuery, array('exclude' => $filterField . '_type', 'include' => 'child_type'));
		}
	}

	/**
	 * Add selected filters to solr query
	 *
	 * @param   object   $query  Solarium object that builds the solr query
	 * @param   array   $selectedFilters  list of options currently selected
	 * @return  string
	 */
	public function applyFilters($query, $selectedFilters)
	{
		$filterField = strtolower($this->get('field'));
		$selectedValues = isset($selectedFilters[$filterField]) ? $selectedFilters[$filterField] : array();
		$filterOptions = $this->options->fieldsByKey('value');
		$selectedValues = array_intersect($selectedValues, $filterOptions);
		if (empty($selectedValues))
		{
			return false;
		}
		$queryName = ucfirst($filterField) . '_' . $this->get('id');
		$facetString = '(' . $filterField . ':("' . implode('" OR "', $selectedValues) . '"))';
		$query->addFilter($queryName, $facetString, array(strtolower($filterField) . '_type', 'filter_type'));
		return true;
	}
}
