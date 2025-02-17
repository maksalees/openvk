<?php declare(strict_types=1);
namespace openvk\Web\Models\Entities;
use openvk\Web\Util\DateTime;
use openvk\Web\Models\RowModel;
use openvk\Web\Models\Entities\User;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\Repositories\Clubs;
use openvk\Web\Models\Repositories\Comments;
use Chandler\Database\DatabaseConnection as DB;
use Nette\InvalidStateException as ISE;
use Nette\Database\Table\Selection;

abstract class Postable extends Attachable
{
    /**
    * Column name, that references to an object, that
    * is hieararchically higher than this Postable.
    * 
    * For example: Images belong to User, but Posts belong to Wall.
    * Formally users still own posts, but walls also own posts and they are
    * their direct parent.
    * 
    * @var string
    */
    protected $upperNodeReferenceColumnName = "owner";
    
    private function getTable(): Selection
    {
        return DB::i()->getContext()->table($this->tableName);
    }
    
    function getOwner(): RowModel
    {
        $oid = (int) $this->getRecord()->owner;
        if($oid > 0)
            return (new Users)->get($oid);
        else
            return (new Clubs)->get($oid * -1);
    }
    
    function getVirtualId(): int
    {
        return $this->getRecord()->virtual_id;
    }
    
    function getPrettyId(): string
    {
        return $this->getRecord()->owner . "_" . $this->getVirtualId();
    }
    
    function getPublicationTime(): DateTime
    {
        return new DateTime($this->getRecord()->created);
    }
    
    function getEditTime(): ?DateTime
    {
        $edited = $this->getRecord()->edited;
        if(is_null($edited)) return NULL;
        
        return new DateTime($edited);
    }
    
    function getComments(int $page, ?int $perPage = NULL): \Traversable
    {
        return (new Comments)->getCommentsByTarget($this, $page, $perPage);
    }
    
    function getCommentsCount(): int
    {
        return (new Comments)->getCommentsCountByTarget($this);
    }
	
	function getLastComments()
    {
        return (new Comments)->getLastCommentsByTarget($this);
    }
    
    function getLikesCount(): int
    {
        return sizeof(DB::i()->getContext()->table("likes")->where([
            "model"  => static::class,
            "target" => $this->getRecord()->id,
        ]));
    }
    
    // TODO add pagination
    function getLikers(): \Traversable
    {
        $sel = DB::i()->getContext()->table("likes")->where([
            "model"  => static::class,
            "target" => $this->getRecord()->id,
        ]);
        
        foreach($sel as $like)
            yield (new Users)->get($like->origin);
    }
    
    function toggleLike(User $user): void
    {
        $searchData = [
            "origin" => $user->getId(),
            "model"  => static::class,
            "target" => $this->getRecord()->id,
        ];
        if(sizeof(DB::i()->getContext()->table("likes")->where($searchData)) > 0)
            DB::i()->getContext()->table("likes")->where($searchData)->delete();
        else
            DB::i()->getContext()->table("likes")->insert($searchData);
    }
    
    function hasLikeFrom(User $user): bool
    {
        $searchData = [
            "origin" => $user->getId(),
            "model"  => static::class,
            "target" => $this->getRecord()->id,
        ];
        
        return sizeof(DB::i()->getContext()->table("likes")->where($searchData)) > 0;
    }
    
    function setVirtual_Id(int $id): void
    {
        throw new ISE("Setting virtual id manually is forbidden");
    }
    
    function save(): void
    {
        $vref = $this->upperNodeReferenceColumnName;
        
        $vid  = $this->getRecord()->{$vref} ?? $this->changes[$vref];
        if(!$vid)
            throw new ISE("Can't presist post due to inability to calculate it's $vref post count. Have you set it?");
        
        $pCount = sizeof($this->getTable()->where($vref, $vid));
        if(is_null($this->getRecord())) {
            # lol allow ppl to taint created value
            if(!isset($this->changes["created"]))
                $this->stateChanges("created", time());
            
            $this->stateChanges("virtual_id", $pCount + 1);
        } else {
            $this->stateChanges("edited", time());
        }
        
        parent::save();
    }
    
    use Traits\TAttachmentHost;
    use Traits\TOwnable;
}
