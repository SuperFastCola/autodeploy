<?php

class DeployHandler{ 
	
	private $cf; 
	private $cf_deploy;
	private $cf_deploy_type;
	private $config_source;
	private $config_destination;
	private $config_sample;
	private $path_main;
	private $path_current;
	private $cf_files;
	private $messages;
	private $json;

	public $dir_config;
	
	//constructor
	public function DeployHandler($config_type,$config_dir_from_root,$json_output=false){ 

		$this->path_main 				= preg_replace("/api\/lib/","",dirname(realpath(__FILE__)));
		$this->path_root 				= preg_replace("/www\/$/","",$this->path_main);

		$this->path_current 			= dirname(realpath(__FILE__)) . "/";
		$this->messages = new stdClass();

		$this->cf_files = array();
		$this->cf_files["production"]	= "config-production";
		$this->cf_files["latest"]		= "config-staging-latest.php";
		$this->cf_files["qa"]			= "config-staging-qa.php";
		$this->cf_files["sample"]		= "config-sample.php";
		$this->cf_files["main"]			= "config.php";

		//output json 
		if($json_output){
			$this->json = true;
		}

		$this->dir_config 				= (isset($config_dir_from_root))?$config_dir_from_root . "/":"";

		$this->path_cf_src 				= NULL;
		$this->path_cf_dst				= $this->path_main . $this->cf_files["main"];

		$this->cf_deploy_type = (isset($config_type))?$config_type:"sample";

		$this->createConfigFile($this->cf_deploy_type);
		$this->includeConfigSetVariables();
		$this->repoSync();

	}//end constructor

	private function d($object){
		var_dump($object);
	}

	private function m($message){
		$this->messages->messages[] = $message;
	}

	private function copySampleFile($cf=NULL){
	}

	private function createConfigFile($cf_deploy_type){

		if(!isset($this->cf_files[$cf_deploy_type])){
			die("No config type found for the type: " . $cf_deploy_type);
		}

		if($cf_deploy_type=="sample"){
			$this->path_cf_src = $this->path_main . $this->cf_files[$cf_deploy_type];	
		}	
		else{
			$this->path_cf_src = $this->path_root . $this->dir_config . $this->cf_files[$cf_deploy_type];	
		}
		
		if(file_exists($this->path_cf_src)){
			$this->m("Copying over config with " . $this->path_cf_src);
			copy($this->path_cf_src,$this->path_cf_dst);
		}
		else{
			die("Config source not found => " . $this->path_cf_src);
		}
		
	}

	private function updateDatabase(){

		if(isset($this->cf_deploy['updateDbWhenDeploying']) && isset($this->cf_deploy['deploy_db_path']) ){
				
			$db_absolute_path = $this->path_root . $this->cf_deploy['deploy_db_path'];

			if(file_exists($db_absolute_path)){
				
				$mysqli = new mysqli($this->cf_deploy["db_host"], $this->cf_deploy["db_user"], $this->cf_deploy["db_pass"], $this->cf_deploy["db_name"], $this->cf_deploy["db_port"]);
			 
				if(mysqli_connect_error()) {
				    die('Connect Error (' . mysqli_connect_errno() . ') ' . mysqli_connect_error());
				}//if(mysqli_connect_error()) 
				else{
					$sql_file_contents = file_get_contents($db_absolute_path);
					
					if(!$sql_file_contents){
						die ('Error opening file SQL File');
					}
					else{
						$success = mysqli_multi_query($mysqli,$sql_file_contents);
						if($success){
							$this->m("Connected and Updating: " . $mysqli->host_info);
						}
						else{
							$this->m("MySQLi Error: " . mysqli_errno($mysqli));
						}
						$mysqli->close();
					}
					
				}//end else
			}//	if(file_exists($this->cf_deploy['deploy_db_path'])){
			else{
				die("No file found at: " . $db_absolute_path);
			}
		}//$this->cf_deploy['updateDbWhenDeploying']) && isset($this->cf_deploy['deploy_db_path']

	}

	private function includeConfigSetVariables(){
		require $this->path_cf_dst;

		$this->cf = $env_config;
		$this->cf_deploy = $deploy_config;

	}

	private function repoSync(){

		$deploykey = NULL;

		//gets bitbucket payload and constructs key from slug and owner
		if(isset($_REQUEST["payload"])){
			$payload = json_decode($_REQUEST["payload"]);	
			$deploykey = $payload->repository->slug . ":" . $payload->repository->owner;
		}
		elseif(isset($_REQUEST["key"])){
			$deploykey = $_REQUEST["key"];
		}
		
		if(isset($deploykey) && $deploykey==$this->cf_deploy["deploy_key"]){

			if(isset($this->cf_deploy)){
				if(isset($this->cf_deploy['git_repo_ssh']) && isset($this->cf_deploy['git_repo_branch'])){
					$git_comm = array();
					$git_comm[] = "/usr/bin/git fetch";
					$git_comm[] = "/usr/bin/git reset --hard origin/" . $this->cf_deploy['git_repo_branch'];
					$git_comm[] = "/usr/bin/git pull -u origin " . $this->cf_deploy['git_repo_branch'];

					foreach($git_comm as $g){
						$this->m($g);
						$this->m(shell_exec($g));
						error_log($g);
					}

					//update database at end of function
					$this->updateDatabase();

					if($this->json){
						echo json_encode($this->messages);
					}
					else{
						echo "<pre>";
						echo $this->messages;
						echo "</pre>";
					}
					

				}
				else{
					die("Git Repo location and branch not set");
				}
			}
			else{
				die("No config variables set");
			}
		}
		else{
			die("No deployment key set");
		}

	}
}


?>