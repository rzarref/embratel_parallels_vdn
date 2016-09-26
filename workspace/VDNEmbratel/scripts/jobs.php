<?php

//if (!defined('APS_DEVELOPMENT_MODE')) define ('APS_DEVELOPMENT_MODE', 'on');

require_once "aps/2/runtime.php";
require_once "elemental_api/utils.php";
require_once "elemental_api/jobVOD.php";
require_once "elemental_api/preset.php";
require_once "elemental_api/deltaContents.php";

/**
 * @type("http://embratel.com.br/app/VDNEmbratel/job/2.0")
 * @implements("http://aps-standard.org/types/core/resource/1.0")
 */
class job extends \APS\ResourceBase {

    // Relation with the management context
    /**
        * @link("http://embratel.com.br/app/VDNEmbratel/context/1.0")
        * @required
        */
    public $context;

    /**
        * @type(string)
        * @title("Input URIs")
        * @description("Job Input URIs for video ingestion")
        */
    public $input_URI;

    /**
        * @type(string[])
        * @title("Inputs")
        * @description("Array of Video inputs")
        */
    public $inputs;

    /**
        * @type(string)
        * @title("Username")
        * @description("Username for acess to URI")
        */
    public $username;

    /**
        * @type(string)
        * @title("Password")
        * @description("Username's password for acess to URI")
        */
    public $password;

    /**
        * @type(string)
        * @title("Name")
        * @description("Job Name")
        */
    public $name;

    /**
        * @type(string)
        * @title("Description")
        * @description("Job Description")
        */
    public $description;

    /**
        * @type(string)
        * @title("Screen Format")
        * @description("4:3 / 16:9 ?")
        */
    public $screen_format;

    /**
        * @type(boolean)
        * @title("Extended Configuration (Premium)")
        * @description("Allow transcoder fine-tuning and multiple transmux packaging")
        */
    public $premium;

    /**
        * @type(boolean)
        * @title("HTTPS")
        * @description("Turn on HTTPS feature for live")
        */
    public $https;	

    /**
        * Readonly parameters obtained from Elemental Server
        */
        
    /**
        * @type(integer)
        * @title("Job ID")
        * @description("Job ID in Elemental Server Conductor")
        * @readonly
        */
    public $job_id;

    /**
        * @type(string)
        * @title("Job name")
        * @description("Job Name in Elemental Server Conductor")
        * @readonly
        */
    public $job_name;

    /**
        * @type(string)
        * @title("State")
        * @description("Job current state")
        * @readonly
        */
    public $state;

    /**
        * @type(string)
        * @title("Submit date")
        * @description("Date of job submission")
        * @readonly
        */
    public $submit_date;

    /**
        * @type(string)
        * @title("Elapsed time")
        * @description("Job Elapsed time")
        * @readonly
        */
    public $elapsed_time;

    /**
        * @type(string)
        * @title("Info")
        * @description("Message")
        * @readonly
        */
    public $info;

    /**
        * @type(integer)
        * @title("Counter for async operation")
        * @description("Counts down number of retries for async operation")
        * @readonly
    */
    public $retry;

    /**
        * @type(string)
        * @title("Server Node")
        * @description("Elemental Server node this job is assigned to")
        * @readonly
        */
    public $server_node;

    /**
        * @type(string[])
        * @title("Resolutions")
        * @description("Array of Video Resolutions for the generated streams")
        */
    public $resolutions;

    /**
        * @type(string[])
        * @title("Frame Rates")
        * @description("Array of Frame Rates for the generated streams")
        */
    public $framerates;

    /**
        * @type(string[])
        * @title("Video Bitrates")
        * @description("Array of Video Bitrates for the generated streams")
        */
    public $video_bitrates;

    /**
        * @type(string[])
        * @title("Audio Bitrates")
        * @description("Array of Audio Bitrates for the generated streams")
        */
    public $audio_bitrates;

    #############################################################################################################################################
    ## Definition of the functions that will respond to the different CRUD operations
    #############################################################################################################################################
    public function provision() { 
        \APS\LoggerRegistry::get()->setLogFile("logs/jobs.log");
        \APS\LoggerRegistry::get()->info("Iniciando provisionamento de conteudo(job) ".$this->aps->id);
        $clientid = formatClientID($this->context);
        if( $this->input_URI == null ) {
            $names = array();
            foreach($this->inputs as $input) {
                $fnparts = explode('/',$input);
                $names[] = $fnparts[count($fnparts)-1];
            }

            $dupnames = array();
            \APS\LoggerRegistry::get()->info("Verificando duplicidade de conteúdo ".$this->aps->id);
            foreach( $this->context->vods as $vod ) {
                $fnparts = explode('/',$vod->input_URI);
                $vodFileName = $fnparts[count($fnparts)-1];
                if( in_array($vodFileName, $names)  ) {
                    \APS\LoggerRegistry::get()->info("Found duplicate content:\n".print_r($vod,true));
                    $dupnames[] = $vodFileName;
                }
            }
            \APS\LoggerRegistry::get()->info("Verificando duplicidade de jobs");
            foreach( $this->context->jobs as $job ) {
                if( $this->aps->id == $job->aps->id){
                    continue;
                }
                $fnparts = explode('/',$job->input_URI);
                $jobFileName = $fnparts[count($fnparts)-1];
                if( in_array($jobFileName, $names)  ) {
                    if( $job->state != 'error' & $job->state != 'cancelled' & $job->state != 'complete'){
                        \APS\LoggerRegistry::get()->info("Found duplicate content:\n".print_r($job,true));
                        $dupnames[] = $jobFileName;
                    }
                }
            }
            if( count($dupnames) > 0 ){
                throw new Exception(_("Contents with the following names already were submitted and should be removed before resubmission: ").implode(",", $dupnames));
            }
            \APS\LoggerRegistry::get()->info ("Provisionando jobs para ". count($this->inputs)." Arquivos: ");
            
            //O Job iniciado pelo painel pega o primeiro input
            $this->input_URI = $this->inputs[0];

            $apsc = \APS\Request::getController();
            $apsc2 = $apsc->impersonate($this);
            $context = $apsc2->getResource($this->context->aps->id);
            //aqui geramos os outros jobs para cada um dos inputs
            for( $ix=1; $ix<count($this->inputs); ++$ix) {
                $job = \APS\TypeLibrary::newResourceByTypeId("http://embratel.com.br/app/VDNEmbratel/job/2.0");
                \APS\LoggerRegistry::get()->info ("Criando input ".$this->inputs[$ix]);
                $job->input_URI      = $this->inputs[$ix];
                $job->resolutions    = $this->resolutions;
                $job->video_bitrates = $this->video_bitrates;
                $job->framerates     = $this->framerates;
                $job->audio_bitrates = $this->audio_bitrates;
                $job->username       = $this->username;
                $job->password       = $this->password;
                $job->screen_format  = $this->screen_format;
                $job->premium        = $this->premium;
                $job->https          = $this->https;
                \APS\LoggerRegistry::get()->info ("Dynamically creating new Job for content $job->input_URI");
                $apsc2->linkResource($context, 'jobs', $job);
            }
        }
        \APS\LoggerRegistry::get()->info("Provisioning Client: $clientid Job Input: ".$this->input_URI);
        $presets = new Presets();
        for($i=0;$i<count($this->resolutions);$i++ ) {
            $presets->addPreset(new Preset($this->resolutions[$i],
                    $this->video_bitrates[$i],$this->framerates[$i],
                    $this->audio_bitrates[$i]),$i);
        }
        $toks = explode('/',$this->input_URI);
        $this->name = $toks[count($toks)-1];
//         \APS\LoggerRegistry::get()->info(var_dump($this));
        $level = ($this->premium ? 'prm' : 'std');
        $protocol = ($this->https ? 'https' : 'http');
//         try {
//             ElementalRest::$auth = new Auth( 'elemental','elemental' );		// TODO: trazer usuario/api key
        \APS\LoggerRegistry::get()->info("--> Provisionando Job level=".$level." protocol=".$protocol. " username  $this->username password: $this->password");
        $job = JobVOD::newJobVOD( $this->name, $this->input_URI, $clientid, $level, $presets, $protocol, 
                                    $this->username, $this->password );
//         } catch (Exception $fault) {
//             \APS\LoggerRegistry::get()->error("Error while creating content job, :\n\t" . $fault->getMessage());
//             throw new Exception($fault->getMessage());
//         }

        $this->job_id = $job->id;
        $this->job_name = $job->name;
        $this->state = $job->status;
        
        \APS\LoggerRegistry::get()->info("job_id:" . $this->job_id );
        \APS\LoggerRegistry::get()->info("job_name:" . $this->job_name );
        \APS\LoggerRegistry::get()->info("state:" . $this->state );
        \APS\LoggerRegistry::get()->info("input_URI:" . $this->input_URI );
        \APS\LoggerRegistry::get()->info("<-- Job Provisionado assincronamente");
        throw new \Rest\Accepted($this, "Job Submitted", 10); // Return "202 Accepted"
    }

    public function provisionAsync() {
        \APS\LoggerRegistry::get()->setLogFile("logs/jobs.log");
        $jobstatus = JobVOD::getStatus($this->job_id);
        \APS\LoggerRegistry::get()->info("Called provisionAsync for job id=".$this->job_id);
        \APS\LoggerRegistry::get()->info("Updating state from ".$this->state." to ".$jobstatus->status.'' );
        $this->state = $jobstatus->status.'';
        $this->submit_date  = $jobstatus->start_time.'';
        if( $jobstatus->status == 'complete') {
            return $this->createContents($jobstatus);
        }
        if( $jobstatus->status == 'error' || $jobstatus->status == 'cancelled'){
            if( $jobstatus->error_messages != null && 
                    $jobstatus->error_messages->error != null && 
                    $jobstatus->error_messages->error->message != null) {
                $this->info = $jobstatus->error_messages->error->message.'';
            }
            return;
        }
        
        $this->retry +=1; // Increment the retry counter
        if( $jobstatus->status == 'running' || $this->retry < ConfigConsts::VOD_STATUS_RETRY_COUNT) {
            throw new \Rest\Accepted($this, "Job status=".'$jobstatus->status' , ConfigConsts::VOD_STATUS_UPDATE_INTERVAL); // Return "202 Accepted"
        }
        $this->state = "timed out";
        \APS\LoggerRegistry::get()->info("Job $this->job_id timed out!");
    }

    public function createContents($jobstatus){
        echo "Creating Content\n";
//         $this->info = $jobstatus->asXml();
        $content = DeltaContents::getContentsFromJob($this->job_id);

        $vod = \APS\TypeLibrary::newResourceByTypeId("http://embratel.com.br/app/VDNEmbratel/vod/1.0");
        $vod->content_id            = $content->id;
        $fname = explode('.',$content->fileName);
        array_pop($fname);
        $vod->content_name          = implode('.', $fname);
        $vod->path                  = $content->endpoint;
        $vod->content_creation_ts   = $jobstatus->complete_time."";
//         preg_match('([\d\.]+)', $vod->content_storage_size, $sizeArray);
//         $vod->content_storage_size  = round( $sizeArray[0] / 1000);

        $vod->description           = $content->fileName;/*$this->description;*/
        $vod->content_time_length   = $content->totalMiliSeconds;
        $vod->content_encoding_charged = false;
        $vod->screen_format         = $this->screen_format;
        $vod->premium               = $this->premium;
        $vod->https                 = $this->https;
        $vod->job_id                = $this->job_id;
        $vod->input_URI             = $this->input_URI;
        $vod->resolutions           = $this->resolutions;
        $vod->framerates            = $this->framerates;
        $vod->video_bitrates        = $this->video_bitrates;
        $vod->audio_bitrates        = $this->audio_bitrates;

        \APS\LoggerRegistry::get()->info ("Provisioning new VOD with link to context with data:\n".print_r($vod,true));
        $apsc = \APS\Request::getController();
        $apsc2 = $apsc->impersonate($this);
        $context = $apsc2->getResource($this->context->aps->id);
        $apsc2->linkResource($context, 'vods', $vod);
        JobVOD::archive($this->job_id);
        \APS\LoggerRegistry::get()->info ("Finished provisioning new VOD with link to context for job number $this->job_id");
        return;
    }

    public function configure($new) {
        \APS\LoggerRegistry::get()->setLogFile("logs/jobs.log");
        $jobstatus = JobVOD::getStatus($this->job_id);
        \APS\LoggerRegistry::get()->info("Called configure for job id=".$this->job_id);
    }

    public function upgrade(){
    }

    public function unprovision(){
        \APS\LoggerRegistry::get()->setLogFile("logs/jobs.log");
        \APS\LoggerRegistry::get()->info(sprintf("Iniciando desprovisionamento para job %s-%s",
                $this->job_id, $this->job_name));
        \APS\LoggerRegistry::get()->info(sprintf("Excluindo Job %s",$this->job_id));

        try {
            ElementalRest::$auth = new Auth( 'elemental','elemental' );
            JobVOD::archive($this->job_id);
        } catch (Exception $fault) {
            \APS\LoggerRegistry::get()->info("Error while deleting content job, :\n\t" . $fault->getMessage());
//             throw new Exception($fault->getMessage());
        }
        
        \APS\LoggerRegistry::get()->info(sprintf("Fim desprovisionamento para job %s",
                $this->job_id));
    }

    /*
    ** C u s t o m   p r o c e d u r e s
    */
    
    /**
     * get jobs current status
     * @verb(GET)
     * @path("/updateJobStatus")
     * @param()
     * @returns {object}
     */
    public function updateJobStatus () {
        \APS\LoggerRegistry::get()->setLogFile("logs/jobs.log");
        $jobstatus = JobVOD::getStatus($this->job_id);
        \APS\LoggerRegistry::get()->info("Called updateJobStatus for job id=".$this->job_id.
                " status will be updated from ".$this->state." to ".$jobstatus->status);
        $this->state = $jobstatus->status.'';
        $this->submit_date  = $jobstatus->start_time.'';
        $this->elapsed_time  = $jobstatus->elapsed_time_in_words.'';
        if( $jobstatus->error_messages != null && 
                $jobstatus->error_messages->error != null && 
                $jobstatus->error_messages->error->message != null) {
            $this->info = $jobstatus->error_messages->error->message.'';
        } else {
            $this->info = null;
        }
        $apsc = \APS\Request::getController();
        $apsc->updateResource($this);
        \APS\LoggerRegistry::get()->info("job id=".$this->job_id." Updated!");
        return $this;
    }


    /**
     * cancel job
     * @verb(GET)
     * @path("/cancelJob")
     * @param()
     * @returns {object}
     */
    public function cancelJob () {
        \APS\LoggerRegistry::get()->setLogFile("logs/jobs.log");
        \APS\LoggerRegistry::get()->info("Called cancelJob for job id=".$this->job_id);
        $result = new stdClass();
        try {
            JobVOD::cancel($this->job_id);
            $this->updateJobStatus();
            $result->result = "ok";
            $result->message = "Job Cancelado";
            return $result;
        }
        catch (Exception $fault) {
            \APS\LoggerRegistry::get()->info("Error cancelling job: ". $fault->getMessage());
            $result->result = "error";
            $result->message = $fault->getMessage();
            return $result;
        }
    }

    /**
     * Update traffic usage
     * @verb(GET)
     * @path("/updateJobUsage")
     * @param()
     * @returns {object}
     */ 
    public function updateJobUsage () {
        $usage = array();
        $usage["VDN_VOD_Encoding_Minutes"] = 0;
        return $usage;
    }
}
/*
    http://www.html5videoplayer.net/videos/toystory.mp4
*/
?>
