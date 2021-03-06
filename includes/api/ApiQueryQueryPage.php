<?php
/**
 *
 *
 * Created on Dec 22, 2010
 *
 * Copyright © 2010 Roan Kattouw <Firstname>.<Lastname>@gmail.com
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	// Eclipse helper - will be ignored in production
	require_once( 'ApiQueryBase.php' );
}

/**
 * Query module to get the results of a QueryPage-based special page
 *
 * @ingroup API
 */
class ApiQueryQueryPage extends ApiQueryGeneratorBase {
	private $qpMap;

	/**
	 * Some query pages are useless because they're available elsewhere in the API
	 */
	private $uselessQueryPages = array(
		'MIMEsearch', // aiprop=mime
		'LinkSearch', // list=exturlusage
		'FileDuplicateSearch', // prop=duplicatefiles
	);

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'qp' );
		// We need to do this to make sure $wgQueryPages is set up
		// This SUCKS
		global $IP;
		require_once( "$IP/includes/QueryPage.php" );

		// Build mapping from special page names to QueryPage classes
		global $wgQueryPages;
		$this->qpMap = array();
		foreach ( $wgQueryPages as $page ) {
			if( !in_array( $page[1], $this->uselessQueryPages ) ) {
				$this->qpMap[$page[1]] = $page[0];
			}
		}
	}

	public function execute() {
		$this->run();
	}

	public function executeGenerator( $resultPageSet ) {
		$this->run( $resultPageSet );
	}

	/**
	 * @param $resultPageSet ApiPageSet
	 * @return void
	 */
	public function run( $resultPageSet = null ) {
		global $wgUser;
		$params = $this->extractRequestParams();
		$result = $this->getResult();

		$qp = new $this->qpMap[$params['page']]();
		if ( !$qp->userCanExecute( $wgUser ) ) {
			$this->dieUsageMsg( 'specialpage-cantexecute' );
		}

		$r = array( 'name' => $params['page'] );
		if ( $qp->isCached() ) {
			if ( !$qp->isCacheable() ) {
				$r['disabled'] = '';
			} else {
				$r['cached'] = '';
				$ts = $qp->getCachedTimestamp();
				if ( $ts ) {
					$r['cachedtimestamp'] = wfTimestamp( TS_ISO_8601, $ts );
				}
			}
		}
		$result->addValue( array( 'query' ), $this->getModuleName(), $r );
		
		if ( $qp->isCached() && !$qp->isCacheable() ) {
			// Disabled query page, don't run the query
			return;
		}

		$res = $qp->doQuery( $params['offset'], $params['limit'] + 1 );
		$count = 0;
		$titles = array();
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				// We've had enough
				$this->setContinueEnumParameter( 'offset', $params['offset'] + $params['limit'] );
				break;
			}

			$title = Title::makeTitle( $row->namespace, $row->title );
			if ( is_null( $resultPageSet ) ) {
				$data = array( 'value' => $row->value );
				if ( $qp->usesTimestamps() ) {
					$data['timestamp'] = wfTimestamp( TS_ISO_8601, $row->value );
				}
				self::addTitleInfo( $data, $title );

				foreach ( $row as $field => $value ) {
					if ( !in_array( $field, array( 'namespace', 'title', 'value', 'qc_type' ) ) ) {
						$data['databaseResult'][$field] = $value;
					}
				}

				$fit = $result->addValue( array( 'query', $this->getModuleName(), 'results' ), null, $data );
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'offset', $params['offset'] + $count - 1 );
					break;
				}
			} else {
				$titles[] = $title;
			}
		}
		if ( is_null( $resultPageSet ) ) {
			$result->setIndexedTagName_internal( array( 'query', $this->getModuleName(), 'results' ), 'page' );
		} else {
			$resultPageSet->populateFromTitles( $titles );
		}
	}

	public function getCacheMode( $params ) {
		$qp = new $this->qpMap[$params['page']]();
		if ( $qp->getRestriction() != '' ) {
			return 'private';
		}
		return 'public';
	}

	public function getAllowedParams() {
		return array(
			'page' => array(
				ApiBase::PARAM_TYPE => array_keys( $this->qpMap ),
				ApiBase::PARAM_REQUIRED => true
			),
			'offset' => 0,
			'limit' => array(
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			),
		);
	}

	public function getParamDescription() {
		return array(
			'page' => 'The name of the special page. Note, this is case sensitive',
			'offset' => 'When more results are available, use this to continue',
			'limit' => 'Number of results to return',
		);
	}

	public function getDescription() {
		return 'Get a list provided by a QueryPage-based special page';
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
		) );
	}

	protected function getExamples() {
		return array(
			'api.php?action=query&list=querypage&qppage=Ancientpages'
		);
	}

	public function getVersion() {
		return __CLASS__ . ': $Id: ApiQueryQueryPage.php 99989 2011-10-16 22:24:58Z reedy $';
	}
}
