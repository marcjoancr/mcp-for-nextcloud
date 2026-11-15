package main
import("bytes";"encoding/json";"os";"strings";"text/template")
type Input struct { Template string; Cases [][]string }
func main(){var in Input;if json.NewDecoder(os.Stdin).Decode(&in)!=nil{os.Exit(2)};t,e:=template.New("predicate").Funcs(template.FuncMap{"split":strings.Split}).Parse(in.Template);if e!=nil{os.Exit(3)};out:=[]bool{};for _,values:=range in.Cases{var b bytes.Buffer;if t.Execute(&b,map[string]interface{}{"Config":map[string]interface{}{"Env":values}})!=nil{os.Exit(4)};if b.String()!="true"&&b.String()!="false"{os.Exit(5)};out=append(out,b.String()=="true")};json.NewEncoder(os.Stdout).Encode(out)}
