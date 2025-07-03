<?php

namespace Izzy\Web\Viewers;

/**
 * Class for displaying detailed information as key-value table
 */
class DetailViewer extends TableViewer
{
    private string $keyColumn = 'key';
    private string $valueColumn = 'value';
    
    public function __construct(array $options = [])
    {
        // Disable row striping for DetailViewer by default
        $options = array_merge(['striped' => false], $options);
        parent::__construct($options);
        
        // Default column configuration for DetailViewer
        $this->addColumn($this->keyColumn, 'Parameter', [
            'align' => 'left',
            'width' => '40%',
            'class' => 'param-name'
        ]);
        
        $this->addColumn($this->valueColumn, 'Value', [
            'align' => 'left',
            'width' => '60%',
            'class' => 'param-value'
        ]);
    }
    
    public function setKeyColumn(string $keyColumn): self
    {
        $this->keyColumn = $keyColumn;
        return $this;
    }
    
    public function setValueColumn(string $valueColumn): self
    {
        $this->valueColumn = $valueColumn;
        return $this;
    }
    
    public function setDataFromArray(array $data): self
    {
        $tableData = [];
        foreach ($data as $key => $value) {
            $tableData[] = [
                $this->keyColumn => $key,
                $this->valueColumn => $value
            ];
        }
        
        $this->setData($tableData);
        return $this;
    }
    
    public function render(): string
    {
        // Set special CSS class for DetailViewer
        $this->setOptions(['class' => 'detail-viewer-table']);
        return parent::render();
    }
} 