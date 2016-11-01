<?php

/**
 * INSTRUCTIONS:
 * 1. Rename config.json.example on config.json and edit the config.json
 * 2. Upload this script to your server somewhere it can be publicly accessed
 * 3. Make sure the apache user owns this script (e.g., sudo chmod -R 755 deployer/ )
 * 4  Create a repository that will store the files of projects (e.g., /var/www/project-repository)
 * 5. Make sure the apache user owns this directory (e.g., sudo chown www-data:www-data project-repository)
 * 6. (optional) If the repo already exists on the server, make sure the same apache user from step 3 also owns that
 *    directory (i.e., sudo chown -R www-data:www-data deployer/)
 * 7. Go into your Github Repo > Settings > Service Hooks > WebHook URLs and add the public URL
 *    (e.g., http://example.com/deploy.php)
 *
 **/

if (file_exists('tools.php'))
{
	require_once 'tools.php';
}


final class AutoDeploy
{
	/**
	 * @var $_config
	 */
	private $_config;

	/**
	 * @var array $_errors
	 */
	private $_errors = array();

	/**
	 * Constructor
	 */
	public function __construct()
	{
		// Load config file
		$this->_config = $this->_load_config();

		if ( isset($this->_errors['load_config']) && !empty($this->_errors['load_config']) )
		{
			echo "ERROR: Error load config.";
			exit;
		}

		$this->_deploy();
		echo "SUCCESS: Create release.";
	}

	/**
	 * @param string $config_name
	 * @param string $ext
	 *
	 * @return bool
	 */
	private function _load_config($config_name = 'config', $ext = '.json')
	{
		$config_file = $config_name . $ext;

		if (!file_exists($config_file))
		{
			$this->_errors['load_config']['file_exist']['message'] = 'Dos not exist config file.';
			echo 'Dos not exist config file.';
			return FALSE;
		}

		switch($ext)
		{
			case '.json':
				return json_decode(file_get_contents($config_file), TRUE);
				break;

			default:
				$this->_errors['load_config']['ext_config']['message'] = 'Dos not exist extension config file.';
				echo 'Dos not exist extension config file.';
				return FALSE;
				break;
		}
	}

	private function _deploy()
	{
		$inputJSON = file_get_contents('php://input');
		$github = json_decode( $inputJSON, TRUE );

		if ( isset($github['release']) && !empty($github['release'])
			&& isset($github['repository']) && !empty($github['repository']))
		{
			$projects = isset($this->_config['repositories']) && !empty($this->_config['repositories']) ? $this->_config['repositories'] : FALSE;

			if ($projects)
			{
				$github_project_name = isset($github['repository']['name']) && !empty($github['repository']['name']) ? $github['repository']['name'] : FALSE ;
				$github_tag = isset($github['release']['tag_name']) && !empty($github['release']['tag_name']) ? $github['release']['tag_name'] : FALSE;
				$github_url = isset($github['repository']['html_url']) && !empty($github['repository']['html_url']) ? $github['repository']['html_url'] : FALSE;
				$github_branch = isset($github['release']['target_commitish']) && !empty($github['release']['target_commitish']) ? $github['release']['target_commitish'] : FALSE;

				if ($github_tag)
				{
					foreach ($projects as $project)
					{
						$project_path = isset($project['path']) && !empty($project['path']) ? $project['path'] : FALSE ;
						$project_name = isset($project['project_name']) && !empty($project['project_name']) ? $project['project_name'] : FALSE ;
						$project_branch = isset($project['branch']) && !empty($project['branch']) ? $project['branch'] : FALSE ;
						$project_type_archive = isset($project['type_archive']) && !empty($project['type_archive']) ? $project['type_archive'] : FALSE ;

						if (!is_dir($project_path))
						{
							if (!mkdir($project_path, 0777, true))
							{
//								$this->_errors['deploy']['create_project_dir']['message'] = 'Error create project "' . $project_name . '" directory.';
								echo 'ERROR: Error create project "' . $project_name . '" directory.';
							}
						}

						if ( $project_name == $github_project_name && $github_branch == $project_branch)
						{

							if ($project_type_archive)
							{

								foreach ($project_type_archive as $archive_ext)
								{
									$download_archive = $github_url . '/archive/' . $github_tag . '.' . $archive_ext;
									$github_files = file_get_contents($download_archive);
									$lable = $this->get_lable( $github_tag );
									$archive_name = ( $lable === '-imp') ?  $project_name . '-' . $github_tag . '.' . $archive_ext : $project_name . $lable . '.' . $archive_ext ;
									$local_project_name = $project_path . $archive_name;

									if ( file_put_contents ($local_project_name, $github_files) )
									{

										if ( 'zip' == $archive_ext )
										{
											$zip = new ZipArchive;
											if( $zip->open( $local_project_name ) )
											{
												$i = 0;
												$old_folder_name = $zip->getNameIndex( $i );

												while( $item = $zip->getNameIndex( $i ) )
												{

													$zip->renameIndex( $i, str_replace( $old_folder_name, $project_name . '/', $item ) );
													$i++;

												}

												$zip->close();

											}else{
												die( 'ERROR: Error open archive "' . $archive_name . '".' );
											}
										}
									}
									else
									{
										die('ERROR: Error create archive "' . $archive_name . '".');
									}
								}
							}
						}
					}
				}
				else
				{
					die("ERROR: Dos not exist tag_name.");
				}
			}
			else
			{
				die("ERROR: Dos not exist projects in config.");
			}
		}
		else
		{
			die("ERROR: This is not release.");
		}
	}
	private function get_lable( $string ) {
		return strtolower( preg_replace( '/[v]?[\d\.]+[v]?/', '', $string ) );
	}
}

new AutoDeploy();