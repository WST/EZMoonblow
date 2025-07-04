<?php

namespace Izzy\Web\Viewers;

/**
 * Class for displaying detailed information as key-value table
 */
class DetailViewer extends TableViewer
{
    private string $keyColumn = 'key';
    private string $valueColumn = 'value';
    private bool $showHeader = true;
    
    public function __construct(array $options = [])
    {
        // Extract showHeader option before passing to parent
        $this->showHeader = $options['showHeader'] ?? true;
        unset($options['showHeader']);
        
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
    
    public function setShowHeader(bool $showHeader): self
    {
        $this->showHeader = $showHeader;
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
    
    public function render(): string {
        // Set special CSS class for DetailViewer
        $this->setOptions(['class' => 'detail-viewer-table']);
        
        if (!$this->showHeader) {
            // Render without header
            return $this->renderWithoutHeader();
        }
        
        return parent::render();
    }
    
    private function renderWithoutHeader(): string
    {
        $html = '<table class="' . $this->getTableClass() . '">';
        
        if (!empty($this->caption)) {
            $html .= '<caption>' . htmlspecialchars($this->caption) . '</caption>';
        }
        
        $html .= '<tbody>';
        foreach ($this->data as $index => $row) {
            $rowClass = $this->getRowClass($index);
            $html .= '<tr class="' . $rowClass . '">';
            
            foreach ($this->columns as $key => $column) {
                $value = $row[$key] ?? '';
                $formattedValue = $this->formatValue($value, $column['format']);
                
                $html .= '<td class="' . $column['class'] . '" style="text-align: ' . $column['align'] . ';">';
                $html .= htmlspecialchars($formattedValue);
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        return $html;
    }
}
