#!/usr/bin/php
<?php
/**
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
 * @category Maintenance
 * @package  ImportPhabData
 * @author   Cindy Cicalese (ccicalese@wikimedia.org)
 * @license  http://www.gnu.org/copyleft/gpl.html GPL-2.0-or-later
 * @link     https://www.mediawiki.org/wiki/Extension:PhabGraph
 *
 */
// @codingStandardsIgnoreStart
$IP = getenv( "MW_INSTALL_PATH" ) ? getenv( "MW_INSTALL_PATH" ) : __DIR__ . "/../../..";
if ( !is_readable( "$IP/maintenance/Maintenance.php" ) ) {
	die( "MW_INSTALL_PATH needs to be set to your MediaWiki installation.\n" );
}
require_once ( "$IP/maintenance/Maintenance.php" );
// @codingStandardsIgnoreEnd

/**
 * Maintenance script that imports data from Phabricator into a wiki
 *
 * @ingroup Maintenance
 * @SuppressWarnings(StaticAccess)
 * @SuppressWarnings(LongVariable)
 */
class ImportPhabData extends Maintenance {
	private $client = null;
	private $dry_run = false;
	private $verbose = false;
	private $save_info = false;
	private $delay = false;
	private $phabTasks = [];
	private $projects = [];
	private $users = [];

	public function __construct() {
		parent::__construct();
		$this->mDescription = "CLI utility to import Phabricator data into a wiki.";
		$this->addArg( "project",
			"Phabricator project to search for tasks in.", true );
		$this->addArg( "category",
			"Category of task pages (default: Phabricator Tasks).", false );
		$this->addArg( "task template",
			"Template for Phabricator tasks (default: Phabricator Task).",
			false );
		$this->addArg( "project template",
			"Template for Phabricator projects (default: Phabricator Project).",
			false );
		$this->addArg( "user template",
			"Template for Phabricator users (default: Phabricator User).",
			false );
		$this->addOption( "dry-run", "Don't edit the wiki pages.",
			false, false, 'n' );
		$this->addOption( "verbose", "Verbose output",
			false, false, 'v' );
		$this->addOption( "save-info", "Save import information to wiki page",
			false, true, 's' );
		$this->addOption( "delay", "Delay in seconds before each Conduit API call (default: 0)",
			false, true, 'd' );
		$this->requireExtension( 'PhabTaskGraph' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->dry_run = $this->getOption( 'dry-run' );
		$this->verbose = $this->getOption( 'verbose' );
		$this->save_info = $this->getOption( 'save-info' );
		$this->delay = intval( $this->getOption( 'delay', 0 ) );

		$projectName = $this->getArg( 0 );
		$categoryName = $this->getArg( 1, 'Phabricator Tasks' );
		$taskTemplateName = $this->getArg( 2, 'Phabricator Task' );
		$projectTemplateName = $this->getArg( 3, 'Phabricator Project' );
		$userTemplateName = $this->getArg( 4, 'Phabricator User' );
		if ( $this->verbose ) {
			echo 'Project: ' . $projectName . PHP_EOL;
			echo 'Category: ' . $categoryName . PHP_EOL;
			echo 'Task Template: ' . $taskTemplateName . PHP_EOL;
			echo 'Project Template: ' . $projectTemplateName . PHP_EOL;
			echo 'User Template: ' . $userTemplateName . PHP_EOL;
		}

		require_once $GLOBALS['wgPhabTaskGraphPhabLibPath'] . '/' .
			'__phutil_library_init__.php';
		$phabURL = $GLOBALS['wgPhabTaskGraphPhabURL'];
		$this->client = new ConduitClient( $phabURL );
		$api_token = $GLOBALS['wgPhabTaskGraphConduitAPIToken'];
		$this->client->setConduitToken( $api_token );

		$phabTasks = $this->getPhabricatorTasksFromProject( $projectName );

		$category = Category::newFromName( $categoryName );
		$titles = $category->getMembers();
		$wikiTasks = [];
		foreach ( $titles as $title ) {
			$taskID = $title->getText();
			if ( $taskID[0] === 'T' ) {
				$taskID = substr( $taskID, 1 );
			}
			$wikiTasks[$taskID] = $title;
		}

		foreach ( $wikiTasks as $taskID => $title ) {
			if ( !isset( $this->phabTasks[$taskID] ) ) {
				$this->getPhabricatorTask( $taskID );
			}
		}

		if ( !$this->dry_run ) {
			foreach ( $wikiTasks as $taskID => $title ) {
				$formattedTask = $this->formatTask( $taskID,
					$this->phabTasks[$taskID], $taskTemplateName, $projectTemplateName,
					$userTemplateName );
				$this->editTask( $title, $taskTemplateName, $formattedTask );
			}
		}

		if ( $this->verbose || ( $this->save_info && !$this->dry_run ) ) {
			$info = 'Count of wiki pages in category ' . $categoryName . ': ' .
				count( $wikiTasks ) . PHP_EOL . PHP_EOL;
			$info .= 'Count of Phabricator tasks in project ' . $projectName .
				' and all subtasks: ' . count( $this->phabTasks ) .
				PHP_EOL . PHP_EOL;
			$info .= 'Open Phabricator tasks in project ' . $projectName .
				' that do not have wiki pages:' . PHP_EOL;
			foreach ( $this->phabTasks as $taskID => $task ) {
				if ( !isset( $wikiTasks[$taskID] ) && $task['fromProject'] &&
					$task['status'] === 'open' ) {
					$info .= '* ' . $phabURL . '/T' . $taskID . PHP_EOL;
				}
			}

			if ( $this->verbose ) {
				echo PHP_EOL . $info;
			}

			if ( $this->save_info && !$this->dry_run ) {
				$title = Title::newFromText( $this->save_info );
				if ( $title ) {
					$wikiPage = new WikiPage( $title );
					$edit_summary = 'updated info from Phabricator';
					$flags = EDIT_MINOR;
					$new_content = new WikitextContent( $info );
					$wikiPage->doEditContent( $new_content, $edit_summary, $flags, false,
						User::newSystemUser( 'Maintenance script' ) );
				} else {
					echo 'Invalid page title: ' . $this->save_info . PHP_EOL;
				}
			}
		}
	}

	private function callAPI( $api, $params ) {
		$after = null;
		$allData = [];
		do {
			if ( $this->delay > 0 ) {
				sleep( $this->delay );
			}
			if ( is_null( $after ) ) {
				$p = $params;
			} else {
				$a = [
					"after" => $after
				];
				$p = $params + $a;
			}
			$results = $this->client->callMethodSynchronous( $api, $p );
			$resultData = $results['data'];
			foreach ( $resultData as $data ) {
				$allData[] = $data;
			}
			$after = $results['cursor']['after'];
		} while ( !is_null( $after ) );
		return $allData;
	}

	private function getPhabricatorTasksFromProject( $projectName ) {
		$params = [
			'constraints' => [
				'projects' => [
					$projectName
				],
			],
			'attachments' => [
				'projects' => [
					true
				],
				'columns' => [
					true
				]
			]
		];
		$resultData = $this->callAPI( 'maniphest.search', $params );
		if ( $this->verbose ) {
			echo 'Found ' . count( $resultData ) . ' tasks in project ' .
				$projectName . PHP_EOL;
		}
		foreach ( $resultData as $data ) {
			$this->parseTask( $data, true );
		}
	}

	private function getPhabricatorTask( $taskID ) {
		$params = [
			'constraints' => [
				'ids' => [
					intval( $taskID ),
				],
			],
			'attachments' => [
				'projects' => [
					true
				],
				'columns' => [
					true
				]
			]
		];
		$resultData = $this->callAPI( 'maniphest.search', $params );
		foreach ( $resultData as $data ) {
			$this->parseTask( $data, false );
		}
	}

	private function parseTask( $data, $fromProject ) {
		$taskID = $data['id'];
		if ( isset( $this->phabTasks[$taskID] ) ) {
			if ( $fromProject ) {
				$this->phabTasks[$taskID]['fromProject'] = $fromProject;
			}
			return;
		}
		if ( $this->verbose ) {
			echo '.'; // let the user know it is working
		}
		$task = [];
		$task['name'] = $data['fields']['name'];
		$task['status'] = $data['fields']['status']['value'];
		$task['color'] = $data['fields']['priority']['color'];
		if ( $data['fields']['authorPHID'] ) {
			$this->getUser( $data['fields']['authorPHID'] );
			$task['author'] = $this->users[$data['fields']['authorPHID']];
		} else {
			$task['author'] = null;
		}
		if ( $data['fields']['ownerPHID'] ) {
			$this->getUser( $data['fields']['ownerPHID'] );
			$task['owner'] = $this->users[$data['fields']['ownerPHID']];
		} else {
			$task['owner'] = null;
		}
		$task['projects'] = [];
		foreach ( $data['attachments']['projects']['projectPHIDs'] as $projphID ) {
			$project = [];
			$this->getProject( $projphID );
			$project['name'] = $this->projects[$projphID]['name'];
			if ( isset( $this->projects[$projphID]['parent-name'] ) ) {
				$project['name'] = $this->projects[$projphID]['parent-name'] .
					' (' . $project['name'] . ')';
			}
			if ( array_key_exists( 'columns', $data['attachments'] ) &&
				array_key_exists( $projphID,
				$data['attachments']['columns']['boards'] ) ) {
				$project['column'] =
					$data['attachments']['columns']['boards'][$projphID]['columns'][0]['name'];
			}
			$task['projects'][] = $project;
		}
		$task['fromProject'] = $fromProject;
		$task['subtasks'] = [];
		$this->phabTasks[$taskID] = $task;
		$this->getSubtasks( $taskID );
	}

	private function getSubtasks( $parentTaskID ) {
		$params = [
			'constraints' => [
				'parentIDs' => [
					$parentTaskID
				],
			],
			'attachments' => [
				'projects' => [
					true
				],
				'columns' => [
					true
				]
			]
		];
		$resultData = $this->callAPI( 'maniphest.search', $params );
		foreach ( $resultData as $data ) {
			$this->parseTask( $data, false );
			$this->phabTasks[$parentTaskID]['subtasks'][] = $data['id'];
		}
	}

	private function getProject( $projphID ) {
		if ( isset( $this->projects[$projphID] ) ) {
			return;
		}
		$params = [
			'constraints' => [
				'phids' => [
					$projphID
				],
			]
		];
		$resultData = $this->callAPI( 'project.search', $params );
		if ( count( $resultData ) > 0 ) {
			$this->parseProject( $resultData[0] );
		}
	}

	private function parseProject( $data ) {
		$projphID = $data['phid'];
		if ( isset( $this->projects[$projphID] ) ) {
			return;
		}
		$project = [];
		$project['name'] = $data['fields']['name'];
		$project['color'] = $data['fields']['color']['key'];
		if ( isset( $data['fields']['parent']['name'] ) ) {
			$project['parent-name'] = $data['fields']['parent']['name'];
		}
		$this->projects[$projphID] = $project;
	}

	private function getUser( $userphID ) {
		if ( isset( $this->users[$userphID] ) ) {
			return;
		}
		$params = [
			'constraints' => [
				'phids' => [
					$userphID
				],
			]
		];
		$resultData = $this->callAPI( 'user.search', $params );
		if ( count( $resultData ) > 0 ) {
			$this->parseUser( $resultData[0] );
		}
	}

	private function parseUser( $data ) {
		$userphID = $data['phid'];
		if ( isset( $this->users[$userphID] ) ) {
			return;
		}
		$user = [];
		$user['username'] = $data['fields']['username'];
		$user['realName'] = $data['fields']['realName'];
		$this->users[$userphID] = $user;
	}

	private function formatTask( $taskID, $task, $taskTemplateName,
		$projectTemplateName, $userTemplateName ) {
		$formattedTask = '{{' . $taskTemplateName . PHP_EOL;
		$formattedTask .=
			'|name=' . $task['name'] . PHP_EOL;
		$formattedTask .=
			'|status=' . $task['status'] . PHP_EOL;
		$formattedTask .=
			'|color=' . $task['color'] . PHP_EOL;
		if ( !is_null( $task['author'] ) ) {
			$formattedTask .=
				'|author=' . $this->formatUser( 'author', $task['author'],
				$userTemplateName ) . PHP_EOL;
		}
		if ( !is_null( $task['owner'] ) ) {
			$formattedTask .=
				'|owner=' . $this->formatUser( 'owner', $task['owner'],
				$userTemplateName ) . PHP_EOL;
		}
		if ( count( $task['projects'] ) > 0 ) {
			$formattedTask .=
				'|projects=';
			foreach ( $task['projects'] as $project ) {
				$formattedTask .=
					$this->formatProject( $project, $projectTemplateName );
			}
			$formattedTask .= PHP_EOL;
		}
		if ( isset( $task['subtasks'] ) && count( $task['subtasks'] ) > 0 ) {
			$formattedTask .=
				'|subtasks=' . implode( ',', $task['subtasks'] ) . PHP_EOL;
		}
		$formattedTask .= '}}' . PHP_EOL;
		return $formattedTask;
	}

	private function formatUser( $type, $user, $userTemplateName ) {
		$formattedUser = '{{' . $userTemplateName . PHP_EOL;
		$formattedUser .=
			'|type=' . $type . PHP_EOL;
		$formattedUser .=
			'|username=' . $user['username'] . PHP_EOL;
		$formattedUser .=
			'|realName=' . $user['realName'] . PHP_EOL;
		$formattedUser .= '}}';
		return $formattedUser;
	}

	private function formatProject( $project, $projectTemplateName ) {
		$column = null;
		if ( isset( $project['column'] ) ) {
			$name = $project['name'];
			$column = $project['column'];
		} else {
			$pos = strpos( $project['name'], ' (' );
			if ( $pos !== false ) {
				$name = substr( $project['name'], 0, $pos );
				$column = substr( $project['name'], $pos + 2, -1);
			} else {
				$name = $project['name'];
			}
		}
		$formattedProject = '{{' . $projectTemplateName . PHP_EOL;
		$formattedProject .= '|name=' . $name . PHP_EOL;
		if ( !is_null( $column ) ) {
			$formattedProject .= '|column=' . $column . PHP_EOL;
		}
		$formattedProject .= '}}';
		return $formattedProject;
	}

	private function editTask( $title, $taskTemplateName, $formattedTask ) {
		if ( $this->verbose ) {
			echo '.'; // let the user know it is working
		}
		if ( $title->getContentModel() !== CONTENT_MODEL_WIKITEXT ) {
			echo $title->getPrefixedText() . ' is not a wikitext page.' . PHP_EOL;
			return;
		}
		$wikiPage = new WikiPage( $title );
		$wikiPageContent = $wikiPage->getContent();
		$articleText = $wikiPageContent->getNativeData();

		$pos = strpos( $articleText, '{{' . $taskTemplateName . PHP_EOL );
		if ( $pos !== false ) {
			$articleText = substr( $articleText, 0, $pos );
		}
		$articleText .= $formattedTask;
		$edit_summary = 'updated task from Phabricator';
		$flags = EDIT_MINOR;
		$new_content = new WikitextContent( $articleText );
		$wikiPage->doEditContent( $new_content, $edit_summary, $flags, false,
			User::newSystemUser( 'Maintenance script' ) );
	}
}

$maintClass = "ImportPhabData";
require_once RUN_MAINTENANCE_IF_MAIN;
